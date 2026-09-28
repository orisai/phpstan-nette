<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\TemplateDirectoryListing;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use function array_key_exists;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_string;
use function sha1_file;

/**
 * @phpstan-import-type FactsArrayShape from PhpRenderFacts
 */
final class PhpFactsCache
{

	// Bumped on every facts-shape change; isFresh() requires an exact match, so envelopes written
	// by any other shape (including pre-versioned ones missing the field) read stale. Version 3:
	// walk semantics gained the convention channel - a version-2 envelope over unchanged files
	// would rehash fresh forever while serving convention-less candidates. Version 4: the NEW
	// channel gained the resolvedness guard - a version-3 envelope would keep serving class-level
	// candidates seeded by dead overridden-ancestor createTemplate() bodies. Version 5: facts
	// gained the views/mutations/open-view-set domain - a version-4 envelope lacks the keys
	// fromArray() now requires. Version 6: public non-dispatch helpers reachable in-class only
	// from shutdown degraded from EFFECTIVE_NO to MAYBE - a version-5 envelope would keep serving
	// their excluded-view facts. Version 7: facts gained the discovery domain and the envelope its
	// two invalidation inputs (resolved mapping value + re-probed existence-set) - a version-6
	// envelope lacks the discovery key and its probes would never be re-validated. Version 8: the
	// existence-set became kind-tagged (dir probes re-validated with is_dir, file probes with
	// is_file) and the envelope gained the resolved formulas-assignment value - a version-7
	// envelope's flat existence-set misses dir/file swaps and its missing formulas field would
	// keep serving discovery computed under a superseded assignment map. Version 9: the view axis
	// gained the formulas' reverse operation (a template file with no dispatch method is a view),
	// so discovery carries file-derived views and the existence-set a third probe kind whose
	// result is a directory-listing digest - a version-8 envelope has neither. Version 10: facts
	// gained createTemplate()'s resolved control argument - a version-9 envelope lacks the key
	// fromArray() now requires.
	public const FORMAT_VERSION = 10;

	private const NAMESPACE_PREFIX = 'phpfacts|';

	// The read-set isn't known until $compute() has run once, so the entry is stored under a
	// plain class-name key and self-validated by rehashing its own recorded read-set at load
	// time - rememberByManifest()/rememberContentAddressed() both require the manifest/hash
	// upfront, which this call site cannot supply.
	private const ENVELOPE_HASH = 'envelope';

	// sha1_file() failing at write time (unreadable/vanished member) is stored under this sentinel
	// instead of the file's own content: no real sha1_file() result can ever equal it, so isFresh()
	// treats the entry as perpetually stale until a successful write records a real hash.
	private const UNREADABLE_SENTINEL = "\0unreadable";

	private LatteAnalysisCache $cache;

	private TemplateFactoryDefaultResolver $templateFactoryDefault;

	private DiscoveryResolver $discoveryResolver;

	/** @var array<string, PhpRenderFacts> */
	private array $inProcessCache = [];

	// Existence probes repeat heavily across cached classes (shared templates/ dirs), so the
	// per-load re-probe batches through one memoized stat per (kind, path) per run.
	/** @var array<string, bool|string> */
	private array $probedThisRun = [];

	public function __construct(
		LatteAnalysisCache $cache,
		TemplateFactoryDefaultResolver $templateFactoryDefault,
		DiscoveryResolver $discoveryResolver
	)
	{
		$this->cache = $cache;
		$this->templateFactoryDefault = $templateFactoryDefault;
		$this->discoveryResolver = $discoveryResolver;
	}

	/**
	 * @param callable(): PhpRenderFacts $compute
	 */
	public function remember(string $className, callable $compute): PhpRenderFacts
	{
		if (array_key_exists($className, $this->inProcessCache)) {
			return $this->inProcessCache[$className];
		}

		$nodeId = self::NAMESPACE_PREFIX . $className;
		$stored = $this->cache->readContentAddressed(self::ENVELOPE_HASH, $nodeId);

		if ($stored !== null && $this->isFresh($stored)) {
			/** @var array<mixed> $factsData */
			$factsData = $stored['facts'];

			/** @var FactsArrayShape $factsData */
			return $this->inProcessCache[$className] = PhpRenderFacts::fromArray($factsData);
		}

		$facts = $compute();

		// An empty read-set (unresolvable/fileless class) has nothing to rehash, so a persisted
		// entry would validate vacuously forever - compute every run instead.
		if ($facts->getReadSet() !== []) {
			$discovery = $facts->getDiscovery();
			$this->cache->writeContentAddressed(self::ENVELOPE_HASH, $nodeId, [
				'formatVersion' => self::FORMAT_VERSION,
				'facts' => $facts->toArray(),
				'readSetHashes' => self::hashReadSet($facts->getReadSet()),
				'factoryDefault' => $this->templateFactoryDefault->resolve(),
				'mapping' => $this->discoveryResolver->resolveMapping(),
				'formulas' => $this->discoveryResolver->getFormulas(),
				'existenceSet' => $discovery === null ? [] : $discovery->getExistenceSet(),
			]);
		}

		return $this->inProcessCache[$className] = $facts;
	}

	/**
	 * @param array<mixed> $stored
	 */
	private function isFresh(array $stored): bool
	{
		if (($stored['formatVersion'] ?? null) !== self::FORMAT_VERSION) {
			return false;
		}

		if (!isset($stored['facts']) || !is_array($stored['facts'])) {
			return false;
		}

		// The factory-default rung reads the live DI container, which no read-set file reflects -
		// the RESOLVED value is compared instead. array_key_exists (not ??) so envelopes predating
		// the field can never validate against a currently-null default.
		if (
			!array_key_exists('factoryDefault', $stored)
			|| $stored['factoryDefault'] !== $this->templateFactoryDefault->resolve()
		) {
			return false;
		}

		// Same live-container discipline for the presenter mapping discovery derives names from.
		if (
			!array_key_exists('mapping', $stored)
			|| $stored['mapping'] !== $this->discoveryResolver->resolveMapping()
		) {
			return false;
		}

		// Same live-config discipline for the formula-assignment map discovery derives candidates
		// from - a config-only assignment change reflects in no read-set file.
		if (
			!array_key_exists('formulas', $stored)
			|| $stored['formulas'] !== $this->discoveryResolver->getFormulas()
		) {
			return false;
		}

		// Discovery candidates depend on which probed paths existed at compute time - files no
		// read-set hash reflects (most never exist) - and on the CONTENTS of the directories the
		// formulas' reverse operation enumerates, which no stat reflects at all. Each probe is
		// re-validated with its recorded kind's own function (is_dir/is_file/listing digest), so a
		// dir replaced by a same-named file - or a template appearing in an enumerated directory -
		// changes the result. Any change invalidates the envelope.
		$existenceSet = $stored['existenceSet'] ?? null;
		if (!is_array($existenceSet)) {
			return false;
		}

		foreach ($existenceSet as $probe) {
			if (
				!is_array($probe)
				|| !is_string($probe['path'] ?? null)
				|| !self::isValidProbeResult($probe['kind'] ?? null, $probe['result'] ?? null)
			) {
				return false;
			}

			/** @var DiscoveryFact::PROBE_* $kind */
			$kind = $probe['kind'];
			if ($this->probeResult($kind, $probe['path']) !== $probe['result']) {
				return false;
			}
		}

		$readSetHashes = $stored['readSetHashes'] ?? null;
		if (!is_array($readSetHashes)) {
			return false;
		}

		foreach ($readSetHashes as $file => $hash) {
			if (!is_string($file) || !is_string($hash) || !is_file($file) || sha1_file($file) !== $hash) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $kind
	 * @param mixed $result
	 */
	private static function isValidProbeResult($kind, $result): bool
	{
		if ($kind === DiscoveryFact::PROBE_LISTING) {
			return is_string($result);
		}

		return ($kind === DiscoveryFact::PROBE_DIR || $kind === DiscoveryFact::PROBE_FILE)
			&& is_bool($result);
	}

	/**
	 * @param DiscoveryFact::PROBE_* $kind
	 * @return bool|string
	 */
	private function probeResult(string $kind, string $path)
	{
		return $this->probedThisRun[$kind . "\0" . $path] ??= self::probe($kind, $path);
	}

	/**
	 * @param DiscoveryFact::PROBE_* $kind
	 * @return bool|string
	 */
	private static function probe(string $kind, string $path)
	{
		if ($kind === DiscoveryFact::PROBE_DIR) {
			return is_dir($path);
		}

		return $kind === DiscoveryFact::PROBE_FILE
			? is_file($path)
			: TemplateDirectoryListing::digest($path);
	}

	/**
	 * @param list<string> $readSet
	 * @return array<string, string>
	 */
	private static function hashReadSet(array $readSet): array
	{
		$hashes = [];
		foreach ($readSet as $file) {
			$hash = is_file($file) ? sha1_file($file) : false;
			$hashes[$file] = $hash === false ? self::UNREADABLE_SENTINEL : $hash;
		}

		return $hashes;
	}

}
