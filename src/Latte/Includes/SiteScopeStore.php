<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use Throwable;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function sha1;
use function str_repeat;
use function strlen;
use function strncmp;
use function strtr;
use function uniqid;
use const SORT_STRING;

final class SiteScopeStore
{

	private string $storeDirPath;

	// Lazy, never read at construction time: every public method below that needs the loaded
	// entries goes through entries() - a caller that never calls get()/sliceHash()/
	// replaceForIncluders() (e.g. every narrowing consumer when orisaiNette.latte.narrowing.enabled is off,
	// which short-circuits BEFORE reaching this class at all) never touches the store directory,
	// keeping the opt-in flag's "no store reads" claim literally true regardless of whether a
	// store directory happens to exist on disk for a downstream consumer who hasn't opted in.

	/** @var array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}>|null */
	private ?array $loadedEntries = null;

	public function __construct(string $storeDirPath)
	{
		$this->storeDirPath = $storeDirPath;
	}

	/** @return array{sha: string, vars: array<string, string>, args: array<string, string>}|null */
	public function get(string $key, string $expectedIncluderSha): ?array
	{
		$entry = $this->entries()[$key] ?? null;
		if ($entry === null || $entry['sha'] !== $expectedIncluderSha) {
			return null;
		}

		return $entry;
	}

	public function sliceHash(string $includerRel, string $includerSha): string
	{
		$slice = self::matchingSlice($this->entries(), $includerRel, $includerSha);
		self::deepKsort($slice);

		return sha1(self::exportArray($slice, 0));
	}

	// The value sliceHash() produces for an includer with zero matching entries - true whether
	// the store is missing, present-but-empty, or simply has no entry for that includer.
	// Narrowing-disabled callers (LatteRoutingParser) use this
	// directly instead of calling sliceHash() at all, so the fingerprint's 4th param never depends
	// on whether a store directory happens to exist on disk when the flag is off.
	public static function emptySliceHash(): string
	{
		return sha1(self::exportArray([], 0));
	}

	// Routing-parser ref emission reads this BEFORE deciding whether to
	// emit a `Helpers::analyzed(\LatteSlice_X::class)` reference on a target - referencing a slice
	// class whose file does not exist would be a class.notFound error, never acceptable.
	public function hasSlice(string $includerRel): bool
	{
		return is_file(self::sliceFilePath($this->storeDirPath, $includerRel));
	}

	/**
	 * @param list<string> $analyzedIncluderRels
	 * @param array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}> $entries
	 */
	public function replaceForIncluders(array $analyzedIncluderRels, array $entries): bool
	{
		$merged = $this->entries();

		foreach ($analyzedIncluderRels as $rel) {
			$prefix = $rel . '#';
			foreach ($merged as $key => $existing) {
				if (strncmp($key, $prefix, strlen($prefix)) === 0) {
					unset($merged[$key]);
				}
			}
		}

		foreach ($entries as $key => $entry) {
			$merged[$key] = $entry;
		}

		self::deepKsort($merged);

		$changed = false;
		foreach ($analyzedIncluderRels as $rel) {
			if (self::writeSliceIfChanged($this->storeDirPath, $rel, $merged)) {
				$changed = true;
			}
		}

		$this->loadedEntries = $merged;

		return $changed;
	}

	// Two sites sharing includer/line/target/context (e.g. two identical {include} tags written on
	// one physical line) collide on this key: the last capture deterministically overlays BOTH
	// edges. Deterministic and safe-direction (never a wrong-context read), but one edge's args can
	// leak into the other's overlay - distinct-line sites are unaffected.
	public static function key(string $includerRel, int $latteLine, string $rawTarget, string $contextHash): string
	{
		return $includerRel . '#' . $latteLine . '#' . $rawTarget . '#' . $contextHash;
	}

	/**
	 * @return array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}>
	 */
	private function entries(): array
	{
		if ($this->loadedEntries === null) {
			$this->loadedEntries = self::loadAll($this->storeDirPath);
		}

		return $this->loadedEntries;
	}

	// `make phpstan-narrowing-init` bootstrap: materializes the directory plus one
	// empty slice for every includer of the current universe that doesn't already have one - a
	// target->slice dependency edge must exist BEFORE a capture change can propagate through it.
	// Never touches a slice that already exists, so re-running this against an
	// already-populated store cannot clobber real captures.

	/**
	 * @param list<string> $includerRels
	 */
	public static function bootstrap(string $storeDirPath, array $includerRels): void
	{
		FileSystem::createDir($storeDirPath);

		foreach ($includerRels as $rel) {
			$file = self::sliceFilePath($storeDirPath, $rel);
			if (is_file($file)) {
				continue;
			}

			self::writeContents($file, $rel, self::exportArray([], 0));
		}
	}

	/**
	 * @param array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}> $merged
	 */
	private static function writeSliceIfChanged(string $storeDirPath, string $rel, array $merged): bool
	{
		$prefix = $rel . '#';
		$sliceEntries = [];
		foreach ($merged as $key => $entry) {
			if (strncmp($key, $prefix, strlen($prefix)) === 0) {
				$sliceEntries[$key] = $entry;
			}
		}

		$file = self::sliceFilePath($storeDirPath, $rel);
		$exportedEntries = self::exportArray($sliceEntries, 0);

		if (is_file($file) && FileSystem::read($file) === self::sliceContents($rel, $exportedEntries)) {
			return false;
		}

		self::writeContents($file, $rel, $exportedEntries);

		return true;
	}

	private static function writeContents(string $file, string $includerRel, string $exportedEntries): void
	{
		$contents = self::sliceContents($includerRel, $exportedEntries);

		$tmp = $file . '.' . uniqid('', true) . '.tmp';
		FileSystem::write($tmp, $contents);
		FileSystem::rename($tmp, $file);
	}

	// The class declaration is guarded by class_exists(): the SAME class name (path-derived from
	// the includer's rel path, see SliceClassName) can be `include`d multiple times within one PHP
	// process - once per SiteScopeStore instance constructed against a store containing this
	// includer's slice - and a bare `class` statement would fatal with "Cannot redeclare class" on
	// the second inclusion. Only the trailing `return` (never affected by the guard) is what this
	// class's own loadAll() actually consumes; CAPTURES_HASH exists purely for PHPStan's own
	// exported-node diffing of this file's real bytes, never read at runtime by this project.
	private static function sliceContents(string $includerRel, string $exportedEntries): string
	{
		$className = SliceClassName::forPath($includerRel);
		$hash = sha1($exportedEntries);

		return "<?php declare(strict_types = 1);\n\n"
			. "if (!class_exists(\\{$className}::class, false)) {\n"
			. "\tclass {$className}\n"
			. "\t{\n\n"
			. "\t\tpublic const CAPTURES_HASH = '{$hash}';\n\n"
			. "\t}\n"
			. "}\n\n"
			. "return {$exportedEntries};\n";
	}

	private static function sliceFilePath(string $storeDirPath, string $includerRel): string
	{
		return $storeDirPath . '/' . SliceClassName::forPath($includerRel) . '.php';
	}

	/**
	 * @param array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}> $entries
	 * @return array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}>
	 */
	private static function matchingSlice(array $entries, string $includerRel, string $includerSha): array
	{
		$prefix = $includerRel . '#';
		$matched = [];
		foreach ($entries as $key => $entry) {
			if (strncmp($key, $prefix, strlen($prefix)) !== 0) {
				continue;
			}

			if ($entry['sha'] !== $includerSha) {
				continue;
			}

			$matched[$key] = $entry;
		}

		return $matched;
	}

	/**
	 * @return array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}>
	 */
	private static function loadAll(string $storeDirPath): array
	{
		if (!is_dir($storeDirPath)) {
			return [];
		}

		$entries = [];
		$files = glob($storeDirPath . '/LatteSlice_*.php');
		foreach ($files === false ? [] : $files as $file) {
			foreach (self::loadOne($file) as $key => $entry) {
				$entries[$key] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @return array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}>
	 */
	private static function loadOne(string $file): array
	{
		try {
			$data = include $file;
		} catch (Throwable $e) {
			return [];
		}

		if (!is_array($data)) {
			return [];
		}

		$entries = [];
		foreach ($data as $key => $entry) {
			if (is_string($key) && self::isValidEntry($entry)) {
				$entries[$key] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param mixed $entry
	 */
	private static function isValidEntry($entry): bool
	{
		if (!is_array($entry) || !isset($entry['sha'], $entry['vars'], $entry['args'])) {
			return false;
		}

		if (!is_string($entry['sha'])) {
			return false;
		}

		return self::isStringMap($entry['vars']) && self::isStringMap($entry['args']);
	}

	/**
	 * @param mixed $value
	 */
	private static function isStringMap($value): bool
	{
		if (!is_array($value)) {
			return false;
		}

		foreach ($value as $name => $type) {
			if (!is_string($name) || !is_string($type)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<mixed> $array
	 */
	private static function deepKsort(array &$array): void
	{
		ksort($array, SORT_STRING);
		foreach ($array as &$value) {
			if (is_array($value)) {
				self::deepKsort($value);
			}
		}
	}

	/**
	 * @param array<mixed> $array
	 */
	private static function exportArray(array $array, int $depth): string
	{
		if ($array === []) {
			return '[]';
		}

		$indent = str_repeat("\t", $depth + 1);
		$closingIndent = str_repeat("\t", $depth);

		$lines = [];
		foreach ($array as $key => $value) {
			$exportedValue = is_array($value)
				? self::exportArray($value, $depth + 1)
				: self::exportString((string) $value);
			$lines[] = $indent . self::exportString((string) $key) . ' => ' . $exportedValue . ',';
		}

		return "[\n" . implode("\n", $lines) . "\n" . $closingIndent . ']';
	}

	private static function exportString(string $value): string
	{
		return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
	}

}
