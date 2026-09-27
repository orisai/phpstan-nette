<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use Nette\Forms\Container as NetteContainer;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Support\BoundedMap;
use Throwable;
use function array_key_exists;
use function fclose;
use function flock;
use function fopen;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function serialize;
use function sha1;
use function uniqid;
use function unserialize;
use const GLOB_ONLYDIR;
use const LOCK_EX;
use const LOCK_UN;

final class FormShapeCache
{

	private const REMEMBER_CACHE_LIMIT = 2048;

	private const INTERPROCEDURAL_CACHE_LIMIT = 2048;

	/** @var array<string, true> */
	private static array $prunedBaseDirectories = [];

	private string $directory;

	private DependencyRecorder $recorder;

	private ?ShapeDependencyCollector $shapeDependencies;

	private string $defaultContainerClass;

	private BoundedMap $rememberCache;

	private BoundedMap $interproceduralCache;

	/** @var array<string, true> */
	private array $interproceduralMisses = [];

	/** @var array<string, true> */
	private array $interproceduralComputing = [];

	public function __construct(
		string $baseDirectory,
		?DependencyRecorder $recorder = null,
		?ShapeDependencyCollector $shapeDependencies = null,
		?string $codeVersion = null,
		?string $defaultContainerClass = null
	)
	{
		// The wiring supplies recorder + shapeDependencies; codeVersion defaults to the real
		// analyser version. All are optional only for direct-construction tests.
		$this->recorder = $recorder ?? new DependencyRecorder();
		$this->shapeDependencies = $shapeDependencies;
		// The class a custom container/replicator falls back to when its concrete type can't be
		// resolved from the call site. Configured per analysed application via wiring; the Nette
		// base is only the inert default for direct-construction tests.
		$this->defaultContainerClass = $defaultContainerClass ?? NetteContainer::class;
		$this->directory = $baseDirectory . '/v' . ($codeVersion ?? FormsCodeVersion::get(
			$this->defaultContainerClass,
		));
		$this->rememberCache = new BoundedMap(self::REMEMBER_CACHE_LIMIT);
		$this->interproceduralCache = new BoundedMap(self::INTERPROCEDURAL_CACHE_LIMIT);

		// Content-validated, so no clearing — only drop directories from older analyser
		// versions, once, from the main process (workers share this run's directory).
		$isWorker = ($_SERVER['argv'][1] ?? null) === 'worker';
		if (!$isWorker && !isset(self::$prunedBaseDirectories[$baseDirectory])) {
			self::$prunedBaseDirectories[$baseDirectory] = true;
			self::pruneOtherVersions($baseDirectory, $this->directory);
		}
	}

	public function recorder(): DependencyRecorder
	{
		return $this->recorder;
	}

	public function defaultContainerClass(): string
	{
		return $this->defaultContainerClass;
	}

	public function recordShapeDependencies(FormShape $shape): void
	{
		if ($this->shapeDependencies !== null) {
			$this->shapeDependencies->record($shape);
		}
	}

	/**
	 * @template T
	 * @param callable(): T $compute
	 * @return T
	 */
	public function remember(string $fileContentHash, string $nodeId, callable $compute)
	{
		$key = sha1($fileContentHash . '|' . $nodeId);

		if ($this->rememberCache->has($key)) {
			/** @var array{v: mixed, d: array<string, string>} $cached */
			$cached = $this->rememberCache->get($key);
			$this->recorder->replay($cached['d']);

			return $cached['v'];
		}

		$entry = $this->readEntry($key);
		if ($entry !== null) {
			$this->recorder->replay($entry['d']);
			$this->rememberCache->set($key, $entry);

			return $entry['v'];
		}

		[$value, $deps] = $this->computeShapeWithDependencies($compute);
		$this->writeEntry($key, $value, $deps);
		$this->rememberCache->set($key, ['v' => $value, 'd' => $deps]);

		return $value;
	}

	/**
	 * The RegistrationIndex's built reverse maps, content-addressed by a universe manifest hash (the
	 * sorted file => content-hash set the fold reduces). A hit is unconditionally valid: the manifest
	 * captures every universe file's content and this versioned directory captures the extraction code,
	 * so no per-dependency validation applies. One process folds and writes under an exclusive lock;
	 * peers reaching the same manifest while it folds block, then load its result — the whole-universe
	 * fold runs once per manifest per run instead of once per demanding worker.
	 *
	 * @param callable(): array{h: array<string, list<array{file: string, fact: array<string, mixed>}>>, p: array<string, list<array{file: string, fact: array<string, mixed>}>>, m: array<string, list<array{file: string, fact: array<string, mixed>}>>} $compute
	 * @return array{h: array<string, list<array{file: string, fact: array<string, mixed>}>>, p: array<string, list<array{file: string, fact: array<string, mixed>}>>, m: array<string, list<array{file: string, fact: array<string, mixed>}>>}
	 */
	public function rememberRegistrationIndex(string $manifest, callable $compute): array
	{
		$key = sha1('regidx|' . $manifest);

		$loaded = $this->readRegistrationIndexEntry($key);
		if ($loaded !== null) {
			return $loaded;
		}

		$lock = $this->acquireLock($key);
		try {
			// Re-read under the lock: a peer may have folded and written the entry while we waited.
			$loaded = $this->readRegistrationIndexEntry($key);
			if ($loaded !== null) {
				return $loaded;
			}

			$value = $compute();
			$this->writeAtomic($key, serialize($value));

			return $value;
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * FormFactSalt's whole-universe token scan, content-addressed by the same universe manifest —
	 * see rememberRegistrationIndex above for why a hit needs no per-dependency validation. Kept
	 * separate from that method rather than generalised: the two blobs have different value shapes
	 * and the validation of a decoded blob is exactly what must not be shared blindly.
	 *
	 * @param callable(): string $compute
	 */
	public function rememberFormFactSalt(string $manifest, callable $compute): string
	{
		$key = sha1('factsalt|' . $manifest);

		$loaded = $this->readFormFactSaltEntry($key);
		if ($loaded !== null) {
			return $loaded;
		}

		$lock = $this->acquireLock($key);
		try {
			$loaded = $this->readFormFactSaltEntry($key);
			if ($loaded !== null) {
				return $loaded;
			}

			$value = $compute();
			$this->writeAtomic($key, serialize($value));

			return $value;
		} finally {
			$this->releaseLock($lock);
		}
	}

	private function readFormFactSaltEntry(string $key): ?string
	{
		$cached = self::readFile($this->directory . '/' . $key . '.ser');
		if ($cached === null) {
			return null;
		}

		$entry = unserialize($cached);

		return is_string($entry) ? $entry : null;
	}

	/**
	 * @return array{h: array<string, list<array{file: string, fact: array<string, mixed>}>>, p: array<string, list<array{file: string, fact: array<string, mixed>}>>, m: array<string, list<array{file: string, fact: array<string, mixed>}>>}|null
	 */
	private function readRegistrationIndexEntry(string $key): ?array
	{
		$cached = self::readFile($this->directory . '/' . $key . '.ser');
		if ($cached === null) {
			return null;
		}

		$entry = unserialize($cached);
		if (
			!is_array($entry)
			|| !isset($entry['h'], $entry['p'], $entry['m'])
			|| !is_array($entry['h'])
			|| !is_array($entry['p'])
			|| !is_array($entry['m'])
		) {
			return null;
		}

		/** @var array{h: array<string, list<array{file: string, fact: array<string, mixed>}>>, p: array<string, list<array{file: string, fact: array<string, mixed>}>>, m: array<string, list<array{file: string, fact: array<string, mixed>}>>} $entry */
		return $entry;
	}

	/**
	 * @param callable(): (FormShape|null) $generator
	 */
	public function loadInterprocedural(string $ipKey, callable $generator): ?FormShape
	{
		$key = $this->interproceduralKey($ipKey);

		$shape = $this->loadShape($key);
		if ($shape !== null) {
			return $shape;
		}

		// A recursive form re-entering its own key: break the cycle without re-locking
		// (flock is not reentrant across handles in one process — it would deadlock).
		if (isset($this->interproceduralComputing[$key])) {
			return null;
		}

		$lock = $this->acquireLock($key);
		try {
			// Re-read from disk under the lock (a peer worker may have written the entry while
			// we waited), bypassing the in-process miss flag loadShape just set. A hit is a
			// read-to-use, so replay its dependencies into the enclosing shape.
			$entry = $this->readEntry($key);
			if ($entry !== null && $entry['v'] instanceof FormShape) {
				$this->recorder->replay($entry['d']);

				return $this->cacheShape($key, $entry['v'], $entry['d']);
			}

			$this->interproceduralComputing[$key] = true;
			try {
				[$shape, $deps] = $this->computeShapeWithDependencies($generator);
			} finally {
				unset($this->interproceduralComputing[$key]);
			}

			if ($shape === null) {
				$this->interproceduralMisses[$key] = true;

				return null;
			}

			// The generator ran in a nested frame whose records also reached the enclosing
			// frame, so the caller already holds these dependencies — cache without replaying.
			$this->writeEntry($key, $shape, $deps);

			return $this->cacheShape($key, $shape, $deps);
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * @param array<string, string> $dependencies
	 */
	public function storeInterprocedural(string $ipKey, FormShape $shape, array $dependencies): void
	{
		$key = $this->interproceduralKey($ipKey);

		// A method with several `return $form` arms stores one shape per arm under the same key;
		// join them so a field present in only one arm degrades to maybe-present (joinBranch),
		// instead of the first arm silently winning. The store is called once per return within a
		// single analysis of the owning method, and the entry depends on that method's file, so a
		// file edit invalidates the entry before it is re-stored — the incremental join stays
		// deterministic across cold/warm runs. Existence is checked without replaying the existing
		// entry into the collector's frame, which is building a new entry, not depending on the old.
		$existing = $this->peekInterprocedural($key);
		if ($existing !== null) {
			$shape = $existing['v']->joinBranch($shape);
			$dependencies = $existing['d'] + $dependencies;
		}

		$this->writeEntry($key, $shape, $dependencies);
		$this->cacheShape($key, $shape, $dependencies);
	}

	/**
	 * @return array{v: FormShape, d: array<string, string>}|null
	 */
	private function peekInterprocedural(string $key): ?array
	{
		if ($this->interproceduralCache->has($key)) {
			/** @var array{v: FormShape, d: array<string, string>} $cached */
			$cached = $this->interproceduralCache->get($key);

			return $cached;
		}

		$entry = $this->readEntry($key);
		if ($entry === null || !$entry['v'] instanceof FormShape) {
			return null;
		}

		return ['v' => $entry['v'], 'd' => $entry['d']];
	}

	public function lookupInterprocedural(string $ipKey): ?FormShape
	{
		return $this->loadShape($this->interproceduralKey($ipKey));
	}

	public function clear(): void
	{
		$this->rememberCache->clear();
		$this->interproceduralCache->clear();
		$this->interproceduralMisses = [];
		self::deleteDirectory($this->directory);
	}

	private function interproceduralKey(string $ipKey): string
	{
		return sha1('ip|' . $ipKey);
	}

	/**
	 * @template T
	 * @param callable(): T $compute
	 * @return array{0: T, 1: array<string, string>}
	 */
	public function computeShapeWithDependencies(callable $compute): array
	{
		$this->recorder->beginFrame();

		try {
			$value = $compute();
			if ($value instanceof FormShape) {
				$this->recordShapeDependencies($value);
			}

			return [$value, $this->recorder->endFrame()];
		} catch (Throwable $e) {
			$this->recorder->endFrame();

			throw $e;
		}
	}

	// Reading a shape to use it replays its dependencies into the enclosing frame, so a reused
	// shape carries its transitive dependencies into the shape built around it.
	private function loadShape(string $key): ?FormShape
	{
		if ($this->interproceduralCache->has($key)) {
			/** @var array{v: FormShape, d: array<string, string>} $cached */
			$cached = $this->interproceduralCache->get($key);
			$this->recorder->replay($cached['d']);

			return $cached['v'];
		}

		if (isset($this->interproceduralMisses[$key])) {
			return null;
		}

		$entry = $this->readEntry($key);
		if ($entry === null || !$entry['v'] instanceof FormShape) {
			$this->interproceduralMisses[$key] = true;

			return null;
		}

		$this->recorder->replay($entry['d']);

		return $this->cacheShape($key, $entry['v'], $entry['d']);
	}

	/**
	 * @param array<string, string> $dependencies
	 */
	private function cacheShape(string $key, FormShape $shape, array $dependencies): FormShape
	{
		unset($this->interproceduralMisses[$key]);
		$this->interproceduralCache->set($key, ['v' => $shape, 'd' => $dependencies]);

		return $shape;
	}

	/**
	 * @return array{v: mixed, d: array<string, string>}|null
	 */
	private function readEntry(string $key): ?array
	{
		$cached = self::readFile($this->directory . '/' . $key . '.ser');
		if ($cached === null) {
			return null;
		}

		$entry = unserialize($cached);
		if (
			!is_array($entry)
			|| !array_key_exists('v', $entry)
			|| !isset($entry['d'])
			|| !is_array($entry['d'])
		) {
			return null;
		}

		/** @var array{v: mixed, d: array<string, string>} $entry */
		if (!$this->recorder->stillValid($entry['d'])) {
			return null;
		}

		return $entry;
	}

	/**
	 * @param mixed $value
	 * @param array<string, string> $dependencies
	 */
	private function writeEntry(string $key, $value, array $dependencies): void
	{
		try {
			$serialized = serialize(['v' => $value, 'd' => $dependencies]);
		} catch (Throwable $e) {
			return;
		}

		$this->writeAtomic($key, $serialized);
	}

	/**
	 * @return resource|null
	 */
	private function acquireLock(string $key)
	{
		try {
			FileSystem::createDir($this->directory);
		} catch (Throwable $e) {
			return null;
		}

		$handle = @fopen($this->directory . '/' . $key . '.lock', 'c');
		if ($handle === false) {
			return null;
		}

		@flock($handle, LOCK_EX);

		return $handle;
	}

	/**
	 * @param resource|null $handle
	 */
	private function releaseLock($handle): void
	{
		if ($handle === null) {
			return;
		}

		@flock($handle, LOCK_UN);
		@fclose($handle);
	}

	private function writeAtomic(string $key, string $serialized): void
	{
		$path = $this->directory . '/' . $key . '.ser';
		$tmp = $this->directory . '/' . $key . '.' . uniqid('', true) . '.tmp';

		try {
			FileSystem::write($tmp, $serialized);
			FileSystem::rename($tmp, $path);
		} catch (Throwable $e) {
			// concurrent workers share the directory; tolerate a racing write/rename
		}
	}

	private static function readFile(string $path): ?string
	{
		if (!is_file($path)) {
			return null;
		}

		try {
			return FileSystem::read($path);
		} catch (Throwable $e) {
			return null;
		}
	}

	private static function pruneOtherVersions(string $baseDirectory, string $current): void
	{
		if (!is_dir($baseDirectory)) {
			return;
		}

		$matches = glob($baseDirectory . '/v*', GLOB_ONLYDIR);
		if ($matches === false) {
			return;
		}

		foreach ($matches as $directory) {
			if ($directory !== $current) {
				self::deleteDirectory($directory);
			}
		}
	}

	private static function deleteDirectory(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}

		try {
			FileSystem::delete($directory);
		} catch (Throwable $e) {
			// best-effort; concurrent pruning by a sibling is harmless
		}
	}

}
