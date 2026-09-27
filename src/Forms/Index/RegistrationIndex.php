<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Index;

use OriPhpstan\Nette\Forms\Cache\FingerprintValidator;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use PHPStan\File\FileFinder;
use Throwable;
use function array_keys;
use function array_merge;
use function array_pop;
use function count;
use function implode;
use function is_array;
use function is_file;
use function ksort;
use function sha1;
use function sha1_file;
use function sort;

/**
 * The order-independent fold over every analysed file's registration facts. Reverse maps answer
 * "which sites contribute to interprocedural key K" and a contributor-set fingerprint answers
 * "has that set moved" — both computed once per process from the sorted file universe.
 *
 * $analysedPaths here is wired from the config's declared paths (`%paths%`), not PHPStan's
 * CLI-narrowed `%analysedPaths%` — a single-file/IDE run must still fold the whole project's
 * registrations, never just the one file being analysed.
 *
 * Not final: the file universe is enumerated through the protected enumerateUniverse() seam so a
 * test can drive the fold with an explicit ordering (FileFinder always sorts, so it cannot).
 */
class RegistrationIndex implements FingerprintValidator
{

	/** @var list<string> */
	private array $analysedPaths;

	private FileFinder $fileFinder;

	private FileFactIndex $facts;

	private ?FormShapeCache $cache;

	private bool $built = false;

	/** @var array<string, list<array{file: string, fact: RegistrationFact}>> */
	private array $handlerContrib = [];

	/** @var array<string, list<array{file: string, fact: RegistrationFact}>> */
	private array $passThroughContrib = [];

	/** @var list<array{file: string, fact: RegistrationFact}> */
	private array $pendingHandler = [];

	/** @var list<array{file: string, fact: RegistrationFact}> */
	private array $pendingPassThrough = [];

	/** @var array<string, list<array{file: string, fact: RegistrationFact}>> */
	private array $mutationContrib = [];

	/** @var list<array{file: string, fact: RegistrationFact}> */
	private array $pendingMutation = [];

	/** @var list<RegistrationFact> */
	private array $pendingParamMutation = [];

	/** @var array<string, list<string>> */
	private array $traitUsers = [];

	/** @var array<string, true> */
	private array $traitNames = [];

	/**
	 * @param list<string> $analysedPaths
	 */
	public function __construct(
		array $analysedPaths,
		FileFinder $fileFinder,
		FileFactIndex $facts,
		?FormShapeCache $cache = null
	)
	{
		$this->analysedPaths = $analysedPaths;
		$this->fileFinder = $fileFinder;
		$this->facts = $facts;
		// Production wires the shared cache so the fold persists once per universe manifest; direct
		// unit construction omits it to drive the raw fold.
		$this->cache = $cache;
	}

	/**
	 * @return list<array{file: string, fact: RegistrationFact}>
	 */
	public function handlerSites(string $class, string $method, int $paramIdx): array
	{
		$this->build();

		return $this->handlerContrib[InterproceduralShapeKey::forMethodParam($class, $method, $paramIdx)] ?? [];
	}

	/**
	 * Pass-through edges are stored PRE-CONTAINMENT: the analysed-paths gate belongs on the callee
	 * method's declaring file, which only reflection resolves, so A6 (IndexShapeResolver) applies
	 * it when it resolves the declaring file. The syntactic calleeClass keyed here is the class the
	 * `$this->callee(...)` call textually sits in; an inherited callee declared in a parent is keyed
	 * to the child (the collector keys by the reflected declaring class — the documented inheritance
	 * caveat this fold cannot see without reflection).
	 *
	 * @return list<array{file: string, fact: RegistrationFact}>
	 */
	public function passThroughEdgesInto(string $class, string $method, int $paramIdx): array
	{
		$this->build();

		return $this->passThroughContrib[InterproceduralShapeKey::forMethodParam($class, $method, $paramIdx)] ?? [];
	}

	/**
	 * Every site in the universe that mutates a component tree through an access to a component of
	 * this name, keyed by the mutated name alone: the access root is often a local whose class no
	 * syntactic fold can name, so the owner is carried on the fact and resolved by the caller
	 * (IndexShapeResolver::componentMayBeMutatedExternally) rather than keyed here. The wildcard
	 * bucket — sites that reached a component through a getter, naming only its owner — answers for
	 * every name, so it is folded in here rather than left to each caller to remember.
	 *
	 * @return list<array{file: string, fact: RegistrationFact}>
	 */
	public function componentMutationSites(string $componentName): array
	{
		$this->build();

		$sites = $this->mutationContrib[$componentName] ?? [];
		if ($componentName === RegistrationFact::MUTATED_ANY) {
			return $sites;
		}

		return array_merge($sites, $this->mutationContrib[RegistrationFact::MUTATED_ANY] ?? []);
	}

	public function fingerprint(string $ipKey): string
	{
		$this->build();

		$ids = [];
		foreach ($this->handlerContrib[$ipKey] ?? [] as $entry) {
			$ids[$this->contributorId($entry)] = true;
		}

		foreach ($this->passThroughContrib[$ipKey] ?? [] as $entry) {
			$ids[$this->contributorId($entry)] = true;
		}

		$keys = array_keys($ids);
		sort($keys);

		return sha1(implode("\n", $keys));
	}

	/**
	 * The FingerprintValidator seam DependencyRecorder validates embedding entries through: refold the
	 * current universe and re-derive the key's contributor fingerprint. Null when the universe cannot be
	 * enumerated/folded (paths gone, unreadable), so an unverifiable entry is treated as invalid rather
	 * than served against an unknowable contributor set.
	 */
	public function currentFingerprint(string $ipKey): ?string
	{
		try {
			return $this->fingerprint($ipKey);
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * @return list<string>
	 */
	protected function enumerateUniverse(): array
	{
		$files = $this->fileFinder->findFiles($this->analysedPaths)->getFiles();
		sort($files);

		return $files;
	}

	private function build(): void
	{
		if ($this->built) {
			return;
		}

		$this->built = true;

		// Enumerate + hash the universe up front (sha1_file is cheap, ~0.05s for the whole tree) to yield
		// the manifest keying the persisted fold below. A missing file falls back to hashing its path so
		// its later appearance still moves the manifest.
		$hashes = [];
		foreach ($this->enumerateUniverse() as $file) {
			$fileHash = is_file($file) ? sha1_file($file) : false;
			$hashes[$file] = $fileHash === false ? sha1($file) : $fileHash;
		}

		// The fold is a pure function of the universe's file set + contents; the manifest is the sorted
		// file => content-hash digest, so any added/removed/edited file moves it. The persisted blob
		// (keyed by manifest, under the code-versioned cache directory) therefore stays a pure function
		// of the manifest — cold and warm reconstruct byte-identical maps.
		$maps = $this->cache === null
			? $this->fold($hashes)
			: $this->cache->rememberRegistrationIndex($this->manifest($hashes), fn (): array => $this->fold($hashes));

		$this->handlerContrib = $this->hydrate($maps['h']);
		$this->passThroughContrib = $this->hydrate($maps['p']);
		$this->mutationContrib = $this->hydrate($maps['m']);
	}

	/**
	 * @param array<string, string> $hashes file => content hash, in enumeration order
	 * @return array{h: array<string, list<array{file: string, fact: array<string, mixed>}>>, p: array<string, list<array{file: string, fact: array<string, mixed>}>>, m: array<string, list<array{file: string, fact: array<string, mixed>}>>}
	 */
	private function fold(array $hashes): array
	{
		foreach ($hashes as $file => $hash) {
			foreach ($this->facts->factsFor($file, $hash) as $fact) {
				$this->collect($file, $fact);
			}
		}

		$handler = [];
		foreach ($this->pendingHandler as $entry) {
			$fact = $entry['fact'];
			foreach ($this->targetClasses($fact->getRegisteringClass()) as $class) {
				$key = InterproceduralShapeKey::forMethodParam($class, $fact->getHandlerMethod(), 0);
				$handler[$key][] = ['file' => $entry['file'], 'fact' => $this->reKeyHandler($fact, $class)];
			}
		}

		$passThrough = [];
		foreach ($this->pendingPassThrough as $entry) {
			$fact = $entry['fact'];
			foreach ($this->targetClasses($fact->getCalleeClass()) as $class) {
				$key = InterproceduralShapeKey::forMethodParam(
					$class,
					$fact->getCalleeMethod(),
					$fact->getCalleeParamIdx(),
				);
				$passThrough[$key][] = ['file' => $entry['file'], 'fact' => $this->reKeyPassThrough($fact, $class)];
			}
		}

		$mutatingCallees = $this->mutatingCalleeKeys();

		$mutation = [];
		foreach ($this->pendingMutation as $entry) {
			$fact = $entry['fact'];
			$handOver = $fact->getHandOverCallee();
			if ($handOver !== null && !isset($mutatingCallees[$handOver])) {
				continue;
			}

			foreach ($this->targetClasses($fact->getMutatingClass()) as $class) {
				$mutation[$fact->getMutatedComponent()][] = [
					'file' => $entry['file'],
					'fact' => $this->reKeyMutation($fact, $class),
				];
			}
		}

		return [
			'h' => $this->finalize($handler),
			'p' => $this->finalize($passThrough),
			'm' => $this->finalize($mutation),
		];
	}

	/**
	 * @param array<string, string> $hashes
	 */
	private function manifest(array $hashes): string
	{
		$files = array_keys($hashes);
		sort($files);

		$parts = [];
		foreach ($files as $file) {
			$parts[] = $file . "\x1f" . $hashes[$file];
		}

		// The same identity string that salts every per-file fact entry (see
		// FileFactIndex::configIdentity) — the fold consumes those entries, so the blob key must
		// move whenever they do; two configs sharing a cache directory never collide on either.
		$parts[] = "\x1f" . $this->facts->configIdentity();

		return sha1(implode("\n", $parts));
	}

	private function collect(string $file, RegistrationFact $fact): void
	{
		switch ($fact->getKind()) {
			case RegistrationFact::KIND_TRAIT_USE:
				$this->traitUsers[$fact->getTraitName()][] = $fact->getClassName();
				$this->traitNames[$fact->getTraitName()] = true;

				break;
			case RegistrationFact::KIND_EVENT_HANDLER:
				$this->pendingHandler[] = ['file' => $file, 'fact' => $fact];

				break;
			case RegistrationFact::KIND_PARAM_PASS_THROUGH:
				$this->pendingPassThrough[] = ['file' => $file, 'fact' => $fact];

				break;
			case RegistrationFact::KIND_COMPONENT_MUTATION:
				$this->pendingMutation[] = ['file' => $file, 'fact' => $fact];

				break;
			case RegistrationFact::KIND_PARAM_MUTATION:
				$this->pendingParamMutation[] = $fact;

				break;
		}
	}

	/**
	 * The callee keys whose named argument really is registered on: seeded with the parameters a
	 * method mutates itself and closed over the parameters it hands on, so a two-step helper chain
	 * resolves while a self-recursive read-only helper (trySetDefaultValues) never enters the set.
	 * The closure is bounded by the hand-over count, which terminates any cycle.
	 *
	 * @return array<string, true>
	 */
	private function mutatingCalleeKeys(): array
	{
		$mutated = [];
		$handOvers = [];
		foreach ($this->pendingParamMutation as $fact) {
			$callee = $fact->getHandOverCallee();
			if ($callee === null) {
				$mutated[$fact->getParamKey()] = true;
			} else {
				$handOvers[] = [$fact->getParamKey(), $callee];
			}
		}

		for ($round = count($handOvers); $round > 0; $round--) {
			$changed = false;
			foreach ($handOvers as [$paramKey, $callee]) {
				if (isset($mutated[$callee]) && !isset($mutated[$paramKey])) {
					$mutated[$paramKey] = true;
					$changed = true;
				}
			}

			if (!$changed) {
				break;
			}
		}

		return $mutated;
	}

	/**
	 * The concrete classes a fact keyed on $keyingClass belongs to. A non-trait keying class is its
	 * own target; a trait re-keys to every class that transitively uses it (cycle-guarded).
	 *
	 * Trait-use adaptations (as/insteadof) are not modelled — FileFactIndex does not capture them,
	 * so an aliased trait method is re-keyed verbatim (a theoretical residual with no in-tree grammar).
	 *
	 * @return list<string>
	 */
	private function targetClasses(string $keyingClass): array
	{
		if (!isset($this->traitNames[$keyingClass])) {
			return [$keyingClass];
		}

		$concrete = [];
		$visited = [$keyingClass => true];
		$queue = [$keyingClass];
		while ($queue !== []) {
			$trait = array_pop($queue);
			foreach ($this->traitUsers[$trait] ?? [] as $user) {
				if (isset($visited[$user])) {
					continue;
				}

				$visited[$user] = true;
				if (isset($this->traitNames[$user])) {
					$queue[] = $user;
				} else {
					$concrete[$user] = true;
				}
			}
		}

		return array_keys($concrete);
	}

	private function reKeyHandler(RegistrationFact $fact, string $class): RegistrationFact
	{
		if ($fact->getRegisteringClass() === $class && $fact->getHandlerClass() === $class) {
			return $fact;
		}

		return RegistrationFact::eventHandlerRegistration(
			$class,
			$fact->getRegisteringMethod(),
			$fact->getFormVar(),
			$class,
			$fact->getHandlerMethod(),
			$fact->getEventProperty(),
		);
	}

	private function reKeyMutation(RegistrationFact $fact, string $class): RegistrationFact
	{
		if ($fact->getMutatingClass() === $class) {
			return $fact;
		}

		return RegistrationFact::componentMutation(
			$class,
			$fact->getOwnerScope(),
			$fact->getOwnerComponent(),
			$fact->getMutatedComponent(),
			$fact->getHandOverCallee(),
		);
	}

	private function reKeyPassThrough(RegistrationFact $fact, string $class): RegistrationFact
	{
		if ($fact->getCallerClass() === $class && $fact->getCalleeClass() === $class) {
			return $fact;
		}

		return RegistrationFact::paramPassThrough(
			$class,
			$fact->getCallerMethod(),
			$fact->getCallerOrigin(),
			$class,
			$fact->getCalleeMethod(),
			$fact->getCalleeParamIdx(),
		);
	}

	/**
	 * Dedupes each key's contributors by identity, orders them deterministically, and lowers the facts
	 * to their scalar array form — the shape the persisted blob serializes (RegistrationFact-decoupled,
	 * matching FileFactIndex's content-addressed fact entries). build() hydrates them back.
	 *
	 * @param array<string, list<array{file: string, fact: RegistrationFact}>> $buckets
	 * @return array<string, list<array{file: string, fact: array<string, mixed>}>>
	 */
	private function finalize(array $buckets): array
	{
		$finalized = [];
		foreach ($buckets as $key => $entries) {
			$byId = [];
			foreach ($entries as $entry) {
				$byId[$this->contributorId($entry)] = $entry;
			}

			ksort($byId);
			$lowered = [];
			foreach ($byId as $entry) {
				$lowered[] = ['file' => $entry['file'], 'fact' => $entry['fact']->toArray()];
			}

			$finalized[$key] = $lowered;
		}

		return $finalized;
	}

	/**
	 * @param array<string, list<array{file: string, fact: array<string, mixed>}>> $map
	 * @return array<string, list<array{file: string, fact: RegistrationFact}>>
	 */
	private function hydrate(array $map): array
	{
		$hydrated = [];
		foreach ($map as $key => $entries) {
			$out = [];
			foreach ($entries as $entry) {
				$out[] = ['file' => $entry['file'], 'fact' => RegistrationFact::fromArray($entry['fact'])];
			}

			$hydrated[$key] = $out;
		}

		return $hydrated;
	}

	/**
	 * @param array{file: string, fact: RegistrationFact} $entry
	 */
	private function contributorId(array $entry): string
	{
		return $entry['file'] . "\x1f" . $this->factIdentity($entry['fact']);
	}

	private function factIdentity(RegistrationFact $fact): string
	{
		$parts = [];
		foreach ($fact->toArray() as $field => $value) {
			$parts[] = $field . '=' . (is_array($value) ? implode(',', $value) : (string) $value);
		}

		return implode('|', $parts);
	}

}
