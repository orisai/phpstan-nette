<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\DiscoveryClassName;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use Throwable;
use function array_key_exists;
use function array_keys;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function serialize;
use function sha1;
use function sort;
use function str_repeat;
use function strtr;
use function uniqid;
use function usort;
use const SORT_STRING;

/**
 * @phpstan-type DiscoveryRecord array{class: string, view: string|null, kind: string, certainty: string}
 */
final class DiscoveryStore
{

	private const INDEX_FILE_NAME = 'class-index.php';

	private const NO_RECORDS_SALT = 'norecords';

	private string $storeDirPath;

	// Lazy, never read at construction time (SiteScopeStore's own discipline): every consumer with
	// orisai.nette.latte.discovery.enabled off short-circuits before calling any method below, so a stray
	// pre-existing store directory is never glob()'d or include()'d when the flag is off.

	/** @var array<string, list<DiscoveryRecord>>|null */
	private ?array $loadedEntries = null;

	/** @var list<string>|null */
	private ?array $loadedIndex = null;

	/** @var array<string, string>|null */
	private ?array $loadedRecordSalts = null;

	public function __construct(string $storeDirPath)
	{
		$this->storeDirPath = $storeDirPath;
	}

	/**
	 * @return list<DiscoveryRecord>
	 */
	public function recordsForTemplate(string $relPath): array
	{
		return $this->entries()[$relPath] ?? [];
	}

	/**
	 * @return list<string>
	 */
	public function allLinkedTemplates(): array
	{
		$linked = [];
		foreach ($this->entries() as $relPath => $records) {
			if ($records !== []) {
				$linked[] = $relPath;
			}
		}

		sort($linked, SORT_STRING);

		return $linked;
	}

	// The re-derivation universe for the writer: every class whose facts carried a discovery, even
	// with zero records - a chosen candidate whose template does not exist YET only flips through
	// its recorded existence probe, and only indexed classes get their envelope re-validated.

	/**
	 * @return list<string>
	 */
	public function linkedClasses(): array
	{
		if ($this->loadedIndex === null) {
			$universe = [];
			foreach (self::loadIndex($this->storeDirPath . '/' . self::INDEX_FILE_NAME) as $className) {
				$universe[$className] = true;
			}

			// An unreadable or malformed index means "unavailable, recompute" - never "empty". Every
			// class owning records in the store belongs to the universe whatever the index file says,
			// so a corrupted index can never make the writer treat live records as vanished and empty
			// their store files.
			foreach ($this->entries() as $records) {
				foreach ($records as $record) {
					$universe[$record['class']] = true;
				}
			}

			$universe = array_keys($universe);
			sort($universe, SORT_STRING);

			$this->loadedIndex = $universe;
		}

		return $this->loadedIndex;
	}

	// Ref-emission gate (SiteScopeStore::hasSlice() precedent): a template references its own
	// discovery class only when the store file already exists on disk - referencing a class whose
	// file does not exist would be a class.notFound error, never acceptable.
	public function hasTemplateFile(string $relPath): bool
	{
		return is_file(self::templateFilePath($this->storeDirPath, $relPath));
	}

	// Per-template compile-key salt (LatteCompiler), keyed by the compiled template's own class name:
	// LatteAnalysisCache persists across runs, so a store-wide salt would make EVERY cached
	// CompileResult unreachable on any single-record change. Empty-record templates share the
	// no-records salt deliberately - a never-linked template's store file appearing must not churn
	// its compile key.
	public function recordsSaltForTemplateClass(string $templateClassName): string
	{
		if ($this->loadedRecordSalts === null) {
			$salts = [];
			foreach ($this->entries() as $relPath => $records) {
				if ($records !== []) {
					$salts[TemplateClassName::forPath($relPath)] = sha1(serialize($records));
				}
			}

			$this->loadedRecordSalts = $salts;
		}

		return $this->loadedRecordSalts[$templateClassName] ?? self::NO_RECORDS_SALT;
	}

	// Template-file-SET identity: the LatteResultCacheMeta salt. A template whose cached parse
	// predates its store file never baked in the self-ref, so only the SET changing needs the
	// whole-cache invalidation; record content stays out - record changes propagate granularly
	// through each store file's own bytes (RECORDS_HASH).
	public function templateSetHash(): string
	{
		$relPaths = array_keys($this->entries());
		sort($relPaths, SORT_STRING);

		return sha1(implode("\n", $relPaths));
	}

	// Whole-store identity, keys AND records: the fallback salt LatteResultCacheMeta falls back to
	// when the store directory is not itself analysed, where no per-template propagation channel
	// exists at all (see that class's regime note). Never the salt of choice - it moves on every
	// record change.
	public function templateContentHash(): string
	{
		$entries = $this->entries();
		ksort($entries, SORT_STRING);

		return sha1(serialize($entries));
	}

	/**
	 * @param array<string, list<DiscoveryRecord>> $recordsByTemplate
	 * @param list<string> $linkedClasses
	 * @param list<string> $materializeTemplateRels
	 */
	public function replaceWith(array $recordsByTemplate, array $linkedClasses, array $materializeTemplateRels): bool
	{
		$merged = [];
		foreach ($recordsByTemplate as $relPath => $records) {
			$records = self::canonicalRecords($records);
			if ($records !== []) {
				$merged[$relPath] = $records;
			}
		}

		// Every previously-loaded template file is rewritten too: a template whose records
		// vanished keeps an EMPTY file - deleting it would leave the template's cached self-ref
		// pointing at a class.notFound.
		$targets = [];
		foreach (array_keys($this->entries()) as $relPath) {
			$targets[$relPath] = true;
		}

		foreach (array_keys($merged) as $relPath) {
			$targets[$relPath] = true;
		}

		foreach ($materializeTemplateRels as $relPath) {
			$targets[$relPath] = true;
		}

		ksort($targets, SORT_STRING);

		$changed = false;
		$entries = [];
		foreach (array_keys($targets) as $relPath) {
			$records = $merged[$relPath] ?? [];
			if (self::writeTemplateFileIfChanged($this->storeDirPath, $relPath, $records)) {
				$changed = true;
			}

			$entries[$relPath] = $records;
		}

		$index = [];
		foreach ($linkedClasses as $className) {
			$index[$className] = true;
		}

		$index = array_keys($index);
		sort($index, SORT_STRING);

		if (self::writeIndexIfChanged($this->storeDirPath, $index)) {
			$changed = true;
		}

		$this->loadedEntries = $entries;
		$this->loadedIndex = $index;
		$this->loadedRecordSalts = null;

		return $changed;
	}

	// Convergence seam (SiteScopeStore::bootstrap() precedent): the template->store-class dependency
	// edge must exist BEFORE a record change can propagate through it. PreAnalysisIndexBuilder
	// materializes the same set through replaceWith(); this entry point stays for a harness that
	// wants the empty files without a build. Never touches a file that already exists.

	/**
	 * @param list<string> $templateRels
	 */
	public static function bootstrap(string $storeDirPath, array $templateRels): void
	{
		FileSystem::createDir($storeDirPath);

		foreach ($templateRels as $relPath) {
			if (is_file(self::templateFilePath($storeDirPath, $relPath))) {
				continue;
			}

			self::writeContents(
				self::templateFilePath($storeDirPath, $relPath),
				self::templateFileContents($relPath, []),
			);
		}

		$indexFile = $storeDirPath . '/' . self::INDEX_FILE_NAME;
		if (!is_file($indexFile)) {
			self::writeContents($indexFile, self::indexFileContents([]));
		}
	}

	/**
	 * @return array<string, list<DiscoveryRecord>>
	 */
	private function entries(): array
	{
		if ($this->loadedEntries === null) {
			$this->loadedEntries = self::loadAll($this->storeDirPath);
		}

		return $this->loadedEntries;
	}

	/**
	 * @param list<DiscoveryRecord> $records
	 */
	private static function writeTemplateFileIfChanged(string $storeDirPath, string $relPath, array $records): bool
	{
		$file = self::templateFilePath($storeDirPath, $relPath);
		$contents = self::templateFileContents($relPath, $records);

		if (is_file($file) && FileSystem::read($file) === $contents) {
			return false;
		}

		self::writeContents($file, $contents);

		return true;
	}

	/**
	 * @param list<string> $index
	 */
	private static function writeIndexIfChanged(string $storeDirPath, array $index): bool
	{
		$file = $storeDirPath . '/' . self::INDEX_FILE_NAME;
		$contents = self::indexFileContents($index);

		if (is_file($file) && FileSystem::read($file) === $contents) {
			return false;
		}

		self::writeContents($file, $contents);

		return true;
	}

	private static function writeContents(string $file, string $contents): void
	{
		$tmp = $file . '.' . uniqid('', true) . '.tmp';
		FileSystem::write($tmp, $contents);
		FileSystem::rename($tmp, $file);
	}

	// The class declaration is guarded by class_exists() and RECORDS_HASH exists purely for
	// PHPStan's own exported-node diffing of this file's real bytes - the byte change a record
	// rewrite makes IS the signature-level difference that reanalyzes the self-referencing
	// template (SiteScopeStore::sliceContents() precedent). Only the trailing `return` is what
	// loadAll() consumes.

	/**
	 * @param list<DiscoveryRecord> $records
	 */
	private static function templateFileContents(string $relPath, array $records): string
	{
		$className = DiscoveryClassName::forPath($relPath);
		$recordsExport = self::exportRecords($records, 1);
		$hash = sha1($recordsExport);

		return "<?php declare(strict_types = 1);\n\n"
			. "if (!class_exists(\\{$className}::class, false)) {\n"
			. "\tclass {$className}\n"
			. "\t{\n\n"
			. "\t\tpublic const RECORDS_HASH = '{$hash}';\n\n"
			. "\t}\n"
			. "}\n\n"
			. "return [\n"
			. "\t'template' => " . self::exportString($relPath) . ",\n"
			. "\t'records' => {$recordsExport},\n"
			. "];\n";
	}

	/**
	 * @param list<string> $index
	 */
	private static function indexFileContents(array $index): string
	{
		$header = "<?php declare(strict_types = 1);\n\n";

		if ($index === []) {
			return $header . "return [];\n";
		}

		$lines = [];
		foreach ($index as $className) {
			$lines[] = "\t" . self::exportString($className) . ',';
		}

		return $header . "return [\n" . implode("\n", $lines) . "\n];\n";
	}

	private static function templateFilePath(string $storeDirPath, string $relPath): string
	{
		return $storeDirPath . '/' . DiscoveryClassName::forPath($relPath) . '.php';
	}

	/**
	 * Canonical record order - class, then view, then kind, then certainty - with exact
	 * duplicates collapsed, so regeneration is byte-identical regardless of derivation order.
	 *
	 * @param list<DiscoveryRecord> $records
	 * @return list<DiscoveryRecord>
	 */
	private static function canonicalRecords(array $records): array
	{
		usort(
			$records,
			static fn (array $a, array $b): int => [$a['class'], $a['view'] ?? '', $a['kind'], $a['certainty']]
				<=> [$b['class'], $b['view'] ?? '', $b['kind'], $b['certainty']],
		);

		$deduplicated = [];
		$previous = null;
		foreach ($records as $record) {
			if ($record === $previous) {
				continue;
			}

			$deduplicated[] = $record;
			$previous = $record;
		}

		return $deduplicated;
	}

	/**
	 * @param list<DiscoveryRecord> $records
	 */
	private static function exportRecords(array $records, int $depth): string
	{
		if ($records === []) {
			return '[]';
		}

		$recordIndent = str_repeat("\t", $depth + 1);
		$fieldIndent = str_repeat("\t", $depth + 2);
		$closingIndent = str_repeat("\t", $depth);

		$lines = [];
		foreach ($records as $record) {
			$lines[] = $recordIndent . '[';
			foreach (['class', 'view', 'kind', 'certainty'] as $field) {
				$value = $record[$field];
				$lines[] = $fieldIndent . self::exportString($field) . ' => '
					. ($value === null ? 'null' : self::exportString($value)) . ',';
			}

			$lines[] = $recordIndent . '],';
		}

		return "[\n" . implode("\n", $lines) . "\n" . $closingIndent . ']';
	}

	private static function exportString(string $value): string
	{
		return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
	}

	/**
	 * @return array<string, list<DiscoveryRecord>>
	 */
	private static function loadAll(string $storeDirPath): array
	{
		if (!is_dir($storeDirPath)) {
			return [];
		}

		$entries = [];
		$files = glob($storeDirPath . '/LatteDiscovery_*.php');
		foreach ($files === false ? [] : $files as $file) {
			$loaded = self::loadOne($file);
			if ($loaded !== null) {
				$entries[$loaded['template']] = $loaded['records'];
			}
		}

		return $entries;
	}

	/**
	 * @return array{template: string, records: list<DiscoveryRecord>}|null
	 */
	private static function loadOne(string $file): ?array
	{
		try {
			$data = include $file;
		} catch (Throwable $e) {
			return null;
		}

		if (!is_array($data) || !is_string($data['template'] ?? null) || !is_array($data['records'] ?? null)) {
			return null;
		}

		$records = [];
		foreach ($data['records'] as $record) {
			if (!self::isValidRecord($record)) {
				return null;
			}

			$records[] = $record;
		}

		return ['template' => $data['template'], 'records' => self::canonicalRecords($records)];
	}

	/**
	 * @param mixed $record
	 * @phpstan-assert-if-true DiscoveryRecord $record
	 */
	private static function isValidRecord($record): bool
	{
		if (!is_array($record) || !array_key_exists('view', $record)) {
			return false;
		}

		if (!is_string($record['class'] ?? null) || !is_string($record['kind'] ?? null)) {
			return false;
		}

		if (!is_string($record['certainty'] ?? null)) {
			return false;
		}

		return $record['view'] === null || is_string($record['view']);
	}

	/**
	 * @return list<string>
	 */
	private static function loadIndex(string $file): array
	{
		if (!is_file($file)) {
			return [];
		}

		try {
			$data = include $file;
		} catch (Throwable $e) {
			return [];
		}

		if (!is_array($data)) {
			return [];
		}

		// A malformed entry is SKIPPED, never a reason to discard the whole index (loadAll()'s own
		// per-file discipline): linkedClasses() backfills every record-owning class regardless.
		$index = [];
		foreach ($data as $className) {
			if (!is_string($className)) {
				continue;
			}

			$index[] = $className;
		}

		sort($index, SORT_STRING);

		return $index;
	}

}
