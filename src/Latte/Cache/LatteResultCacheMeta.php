<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Discovery\PreAnalysisIndexBuilder;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use function fwrite;
use function implode;
use function is_dir;
use function ksort;
use function realpath;
use function rtrim;
use function sha1;
use function sort;
use function strlen;
use function strncmp;
use const DIRECTORY_SEPARATOR;
use const SORT_STRING;
use const STDERR;

// Closes the NEW-INCOMING-EDGE result-cache window: PHPStan's own result cache invalidates a
// class by its exported signature or the dependency edges DependencyEdgeEmitter attaches - both
// keyed to files that already exist in the analysed set at the time they were last cached. A
// brand-new {include}/{layout}/... site added to a file that was NOT a dependency before (or a
// site removed) changes the edge TOPOLOGY, which no per-file signature reflects; this extension's
// hash covers exactly that, so a topology change invalidates the whole result cache rather than
// silently keeping a stale one. Deliberately coarse (whole-cache invalidation, not per-file) -
// topology changes are rare, so trading fine-grained speed for correctness here is the right cost.
//
// The joined harvest salt covers only the GLOBAL harvest (CustomsHarvester's
// engine-derived filters/functions/macros) - per-{templateType} customs are ordinary
// class-reflection facts already invalidated by PHPStan's own exported-nodes/edge-fingerprint
// machinery when the declaring class's methods/docblocks change (see
// TemplateTypeCustomsInvalidationTest), so no additional salt is needed here for that half.
//
// WHAT BELONGS IN THIS HASH (the ratified doctrine, audited empirically in task 7c):
// RARE, ANALYSIS-WIDE inputs - the edge topology, the global harvest, the store's template-file
// SET - belong here, because coarse whole-cache invalidation is the only channel that can carry
// them and they move seldom enough for that to cost nothing. FREQUENT, PER-FILE inputs - an
// individual template's records above all - must NOT: folding whole-store content in nuked the
// cache on every single record change, which is why task 4 rejected it. Config PARAMETERS need
// nothing here either: PHPStan's own result-cache meta already carries the whole project config
// array (ResultCacheManager::getMeta()'s `projectConfig` key, minus %parametersNotInvalidatingCache%,
// which does not list any latte* parameter), so a orisai.nette.latte.discovery.formulas reassignment already
// discards the cache wholesale - a salt of ours would be dead weight.
//
// The compiled DI CONTAINER is the one live input that qualifies on every clause of that doctrine
// and has no other channel at all: it is not a config parameter PHPStan's own meta carries, no
// analysed file's hash reflects it, and the per-record store channel cannot carry it either
// (FactoryProvidedVars reads the container's wired TemplateFactory dependencies to decide whether a
// template variable is definitely present, and no discovery RECORD changes when that wiring does).
// It is also as rare and as analysis-wide as an input gets - the app either wires nette/security
// and nette/http or it does not - so the coarse channel costs nothing. This is the same
// live-container discipline PhpFactsCache::isFresh() applies to the factory-default rung, moved to
// the envelope that guards the consumer this value actually feeds.
final class LatteResultCacheMeta implements ResultCacheMetaExtension
{

	// The meta key this extension's hash is stored under, published so a spawn test can read the
	// value back out of a result cache file and assert the build never moved it.
	public const KEY = 'orisai.nette.latte.edgeTopology';

	private TemplateEdgeIndex $edgeIndex;

	private ConfigurationGuard $guard;

	/** @var list<string> */
	private array $analysedPaths;

	private string $discoveryStoreDirPath;

	private ?CustomsHarvester $harvester;

	private ?DiscoveryStore $discoveryStore;

	private bool $coarseInvalidationAccepted;

	private ?TemplateFactoryDefaultResolver $templateFactoryDefault;

	/** @var resource|null */
	private $noticeStream;

	private ?PreAnalysisIndexBuilder $indexBuilder;

	private bool $coarseRegimeNoticed = false;

	private bool $indexBuilt = false;

	/**
	 * @param list<string> $analysedPaths
	 * @param resource|null $noticeStream
	 */
	public function __construct(
		ConfigurationGuard $guard,
		TemplateEdgeIndex $edgeIndex,
		array $analysedPaths,
		string $discoveryStoreDirPath,
		?CustomsHarvester $harvester = null,
		?DiscoveryStore $discoveryStore = null,
		bool $coarseInvalidationAccepted = false,
		?TemplateFactoryDefaultResolver $templateFactoryDefault = null,
		$noticeStream = null,
		?PreAnalysisIndexBuilder $indexBuilder = null
	)
	{
		$this->edgeIndex = $edgeIndex;
		$this->guard = $guard;
		$this->analysedPaths = $analysedPaths;
		$this->discoveryStoreDirPath = $discoveryStoreDirPath;
		$this->harvester = $harvester;
		$this->discoveryStore = $discoveryStore;
		$this->coarseInvalidationAccepted = $coarseInvalidationAccepted;
		$this->templateFactoryDefault = $templateFactoryDefault;
		$this->noticeStream = $noticeStream;
		$this->indexBuilder = $indexBuilder;
	}

	public function getKey(): string
	{
		return self::KEY;
	}

	public function getHash(): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		try {
			$this->guard->validate();
		} catch (InvalidConfiguration $e) {
			// Hashes are read before analysis, where an exception surfaces as a raw console crash;
			// the rules validate too and report the message properly.
			return 'invalid';
		}

		if (!$this->guard->isLatteEnabled()) {
			return 'disabled';
		}

		$this->buildDiscoveryIndex();

		$lines = [];
		foreach ($this->edgeIndex->edgeTopology() as $edge) {
			$lines[] = $edge['includer'] . "\x1f" . $edge['target'];
		}

		sort($lines, SORT_STRING);

		$harvestSalt = ($this->harvester !== null ? $this->harvester->harvest() : HarvestedCustoms::empty())->getSaltHash();

		// The discovery salt covers only the store's template-file SET (see
		// DiscoveryStore::templateSetHash()): a template whose cached parse predates its store
		// file never baked in the self-ref, and that window is exactly what a whole-cache
		// invalidation must close. Record CONTENT deliberately stays out - record changes
		// propagate granularly through each store file's own bytes, so folding them here would
		// nuke the cache on every writer-driven change. Disabled short-circuits before any store
		// read (SiteScopeStore discipline).
		//
		// That granular channel EXISTS only while the store directory is itself analysed: a store
		// file's changed RECORDS_HASH reaches its self-referencing template through PHPStan's own
		// dependency machinery, which tracks nothing outside the analysed set. A consumer whose
		// orisai.nette.latte.discovery.storePath sits outside %paths% therefore has no channel at all, and its
		// per-file consumers (orisai.nette.latte.templateTypeMismatch/templateTypeRequired) would serve a
		// verdict computed from records that have since moved - proven in task 7c, where a second
		// renderer's mismatch never surfaced on any number of warm runs while a cold run reported
		// it. The fallback is the whole-store content hash: coarse by necessity, never reached in
		// the recommended layout.
		$discoverySalt = 'disabled';
		$discoveryStore = $this->discoveryStore;
		if ($this->guard->isLatteDiscoveryEnabled() && $discoveryStore !== null) {
			if ($this->storeDirIsAnalysed()) {
				$discoverySalt = $discoveryStore->templateSetHash();
			} else {
				$this->noticeGranularRegimeIsUnreachable();
				$discoverySalt = $discoveryStore->templateContentHash();
			}
		}

		return sha1(
			implode("\n", $lines)
			. "\x1e" . $harvestSalt
			. "\x1e" . $discoverySalt
			. "\x1e" . $this->containerSalt(),
		);
	}

	// The pre-analysis seam, and the only one there is: ResultCacheManager::restore() calls every meta
	// extension exactly once, in the coordinator, before AnalyseApplication hands any file to the
	// analyser - so this is the last moment at which the discovery store can be derived early enough
	// for the parse-time consumers that read it. Workers never construct a ResultCacheManager, so no
	// second builder ever races this one.
	//
	// A SIDE EFFECT ONLY. The build contributes no term to the returned hash: the salts below are the
	// same four they always were, and a meta extension's returned hash drives a TOTAL result-cache
	// wipe when it moves (ResultCacheManager::restore()'s isMetaDifferent branch), which is the coarse
	// behaviour the record-content exclusion documented below exists to avoid.
	//
	// It runs BEFORE the discovery salt is read, and that order is load-bearing in the safe direction.
	// The salt covers the store's template-file SET, which the build can only change when the tree
	// itself changed; reading it first would describe the PREVIOUS run's store - the same generation
	// lag this build exists to remove - and would then move the salt on the following run, turning a
	// freshly populated store into a whole-cache wipe one run late. Building first makes both runs
	// agree, so an unchanged tree hashes identically and the cache is restored.
	private function buildDiscoveryIndex(): void
	{
		$indexBuilder = $this->indexBuilder;
		if ($indexBuilder === null || !$this->guard->isLatteDiscoveryEnabled() || $this->indexBuilt) {
			return;
		}

		$this->indexBuilt = true;
		$indexBuilder->build();
	}

	// Both live-container reads in one salt: the factory-default template class AND the wired
	// dependencies that decide whether a factory-provided variable is definitely present. `absent`
	// is a distinct value from an empty wiring on purpose - "no container to ask" and "a container
	// that wires neither" are different verdicts and must not share a hash.
	private function containerSalt(): string
	{
		$resolver = $this->templateFactoryDefault;
		$wiring = $resolver === null ? null : $resolver->resolveWiring();
		if ($wiring === null || $resolver === null) {
			// No resolver wired and a resolver with no loader file are the SAME verdict - "no
			// container to ask" - and must hash identically, the harvest salt's own
			// absent-vs-unconfigured discipline.
			return 'absent';
		}

		ksort($wiring, SORT_STRING);

		$parts = [(string) $resolver->resolve()];
		foreach ($wiring as $key => $className) {
			$parts[] = $key . '=' . $className;
		}

		return implode("\x1f", $parts);
	}

	// An EMPTY %paths% (PHPStan's own default - the analysed set arrives on the command line) is the
	// one coarse case no layout can undo: orisai.nette.latte.discovery.storePath cannot be inside a set that was
	// never declared, so the whole-store salt is forced on every run for good and the parameter's own
	// "keep it inside %paths%" advice is unfollowable. Every other coarse case IS a layout the
	// consumer can move; this one only looks like one, so it gets said out loud - once per process,
	// on STDERR, leaving machine-readable stdout and the [OK] verdict untouched, and silenced
	// outright by orisai.nette.latte.discovery.coarseInvalidationAccepted for a workflow that means it.
	private function noticeGranularRegimeIsUnreachable(): void
	{
		if ($this->analysedPaths !== [] || $this->coarseInvalidationAccepted || $this->coarseRegimeNoticed) {
			return;
		}

		$this->coarseRegimeNoticed = true;

		fwrite(
			$this->noticeStream ?? STDERR,
			"Note: no paths are declared in the configuration, so the Latte discovery store can never sit\n"
			. "inside the analysed set - its per-record result-cache channel does not exist and every\n"
			. "record change discards the WHOLE result cache. Declare paths: with orisai.nette.latte.discovery.storePath\n"
			. "inside one of them, or set orisai.nette.latte.discovery.coarseInvalidationAccepted: true to accept\n"
			. "the cost and silence this notice.\n",
		);
	}

	// The DECLARED paths, never the CLI-narrowed analysed set (RegistrationIndex's own precedent):
	// which regime applies is a property of the project's layout, so a single-file or IDE run must
	// not flip it and churn the whole cache.
	// A store directory no run has created yet has no realpath, so the very first run answers coarse
	// and a later one may answer granular. Left as is on purpose: the store's own contents move
	// between those two runs anyway, so the regime flip discards no cache the content change was not
	// already discarding.
	private function storeDirIsAnalysed(): bool
	{
		$storeDir = realpath($this->discoveryStoreDirPath);
		if ($storeDir === false) {
			return false;
		}

		foreach ($this->analysedPaths as $analysedPath) {
			$real = realpath($analysedPath);
			if ($real === false || !is_dir($real)) {
				continue;
			}

			// Boundary-anchored on both sides: /a/appfoo must not count as being inside /a/app,
			// and the store directory being an analysed path itself must count.
			$prefix = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			if (strncmp($storeDir . DIRECTORY_SEPARATOR, $prefix, strlen($prefix)) === 0) {
				return true;
			}
		}

		return false;
	}

}
