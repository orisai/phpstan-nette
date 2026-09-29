<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use function getmypid;
use function is_array;
use function sort;
use function str_replace;
use function strpos;
use function uniqid;
use const PHP_BINARY;

// The reusable cold-vs-warm harness. One scenario owns one scratch corpus, one PHPStan tmpDir (so
// one result cache) and one spawned analysis per run; a test seeds files, settles the analysis to
// its fixpoint, mutates the corpus, settles again and compares the settled WARM error set against a
// COLD one taken at the byte-identical tree.
//
// Three properties this class exists to guarantee, each of which a hand-rolled scenario has already
// got wrong once in this repo:
//  1. paths are always a DIRECTORY, never a file list - PHPStan disables the result cache outright
//     ("Result cache not used because only files were passed as analysed paths") when it is handed
//     files, which silently removes every bit of detection power a cache test has;
//  2. the subject is always the FIXPOINT, never a single run - store-writing rules legitimately
//     take a second run to converge, and comparing one warm run against cold would fail on that
//     documented window instead of on staleness;
//  3. cold is taken by deleting the result cache alone, with the corpus untouched, so any
//     difference is a cached verdict served past its inputs and nothing else;
//  4. the compared set carries PHPStan's not-file-specific `errors` too (unmatched ignore patterns,
//     a crashed worker, internal errors) and a run that signals failure without decodable errors
//     throws - a report the analysis never really produced must not read as "(no errors)" and pass
//     every step whose expectation is the empty set.
//
// Extension-agnostic on purpose: the only extension-specific thing is the config, supplied as a
// factory so Forms and DIC can reuse the harness with their own isolated-config builders.
final class InvalidationScenario
{

	private const NOT_FILE_SPECIFIC = '(not file-specific)';

	// Symfony's 60s default is a corpus-size limit wearing a test failure's clothes: Latte's scenarios
	// spawn in ~2s, but this project's own cold analysis takes ~100s and the harness is meant for
	// Forms and DIC corpora too, where the default would abort the spawn for a reason that has
	// nothing to do with what the scenario measures. Still finite, so a genuinely hung child fails.
	private const SPAWN_TIMEOUT_SECONDS = 900;

	/** @var callable(list<string>, string): string */
	private $configFactory;

	private string $projectRoot;

	private string $scratchDir;

	private string $sourceDir;

	private string $tmpDir;

	/**
	 * @param callable(list<string>, string): string $configFactory receives the analysed paths and
	 * the PHPStan tmpDir, returns the path of a ready-to-use isolated config file
	 */
	private function __construct(callable $configFactory, string $projectRoot, string $scratchDir)
	{
		$this->configFactory = $configFactory;
		$this->projectRoot = $projectRoot;
		$this->scratchDir = $scratchDir;
		$this->sourceDir = $scratchDir . '/src';
		$this->tmpDir = $scratchDir . '/pstmp';
	}

	/**
	 * @param callable(list<string>, string): string $configFactory
	 */
	public static function create(string $projectRoot, string $name, callable $configFactory): self
	{
		// Under the project root rather than sys_get_temp_dir(): an analysed path outside it makes
		// every reported file an absolute stranger to the project-relative paths the assertions
		// (and the extensions' own universes) speak in.
		return new self(
			$configFactory,
			$projectRoot,
			$projectRoot . '/var/tmp/' . $name . '-' . getmypid() . '-' . uniqid('', true),
		);
	}

	public function getScratchDir(): string
	{
		return $this->scratchDir;
	}

	public function getSourceDir(): string
	{
		return $this->sourceDir;
	}

	// The path a project-root-relative consumer (a store bootstrap, an expected message) has to
	// speak in, which the per-run uniqid otherwise makes unguessable from the outside.
	public function projectRelative(string $relPath): string
	{
		return str_replace($this->projectRoot . '/', '', $this->sourceDir . '/' . $relPath);
	}

	public function write(string $relPath, string $contents): self
	{
		FileSystem::write($this->sourceDir . '/' . $relPath, $contents);

		return $this;
	}

	public function delete(string $relPath): self
	{
		FileSystem::delete($this->sourceDir . '/' . $relPath);

		return $this;
	}

	public function rename(string $fromRelPath, string $toRelPath): self
	{
		FileSystem::rename($this->sourceDir . '/' . $fromRelPath, $this->sourceDir . '/' . $toRelPath);

		return $this;
	}

	public function run(): AnalysisRun
	{
		return $this->spawn();
	}

	// Runs until the analysis reports it has nothing left to reanalyse. A store-writing extension
	// links on one run and its consumers read those links on the next, so only the fixpoint is a
	// state both a warm and a cold run can be asked about.
	public function settle(int $maxRuns = 6): AnalysisRun
	{
		$run = null;
		for ($attempt = 0; $attempt < $maxRuns; $attempt++) {
			$run = $this->spawn();
			if ($run->isSettled()) {
				return $run;
			}
		}

		throw new RuntimeException(
			"The analysis never settled within $maxRuns runs; last errors:\n"
			. ($run === null ? '(never ran)' : $run->getErrorText()),
		);
	}

	// The corpus is deliberately NOT touched: only the cache file goes, so the run recomputes from
	// the exact tree the warm run just judged.
	public function cold(): AnalysisRun
	{
		FileSystem::delete($this->tmpDir . '/resultCache.php');

		return $this->spawn();
	}

	public function cleanup(): void
	{
		try {
			FileSystem::delete($this->scratchDir);
		} catch (Throwable $exception) {
			// process-private scratch; a stray handle in the just-exited child must not fail a test
		}
	}

	private function spawn(): AnalysisRun
	{
		$configFactory = $this->configFactory;
		$configPath = $configFactory([$this->sourceDir], $this->tmpDir);

		$process = new Process(
			[
				PHP_BINARY,
				$this->projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=json',
				'-vv',
				'-c',
				$configPath,
			],
			$this->projectRoot,
			null,
			null,
			self::SPAWN_TIMEOUT_SECONDS,
		);
		$process->run();

		return new AnalysisRun($this->decodeErrors($process), $process->getErrorOutput());
	}

	/**
	 * @return list<string>
	 */
	private function decodeErrors(Process $process): array
	{
		$exitCode = $process->getExitCode();
		$output = $process->getOutput();

		// 0 (clean) and 1 (errors reported) are the only codes under which a report exists at all;
		// a bootstrap fatal or a killed worker has no error set and must not be compared as one.
		if ($exitCode !== 0 && $exitCode !== 1) {
			$reported = $exitCode === null ? '(none)' : (string) $exitCode;

			throw new RuntimeException(
				"The analysis exited with code $reported:\n" . $output . "\n" . $process->getErrorOutput(),
			);
		}

		try {
			$decoded = Json::decode($output, Json::FORCE_ARRAY);
		} catch (JsonException $exception) {
			// A config error or a bootstrap fatal writes its reason to stderr and leaves stdout empty,
			// so the decode failure alone says nothing about what actually went wrong.
			$decoded = null;
		}

		if (
			!is_array($decoded)
			|| !is_array($decoded['files'] ?? null)
			|| !is_array($decoded['errors'] ?? null)
		) {
			throw new RuntimeException(
				"The analysis produced no decodable report (exit code $exitCode):\n"
				. $output . "\n" . $process->getErrorOutput(),
			);
		}

		/**
		 * @var array<string, array{messages: list<array{message: string, line: int|null, identifier: string|null}>}> $files
		 */
		$files = $decoded['files'];
		/** @var list<string> $notFileSpecific */
		$notFileSpecific = $decoded['errors'];

		$lines = [];
		foreach ($files as $file => $fileMessages) {
			foreach ($fileMessages['messages'] as $message) {
				$lines[] = $this->relativize($file) . ':' . ($message['line'] ?? 0)
					. ' :: ' . ($message['identifier'] ?? '(none)')
					. ' :: ' . $this->relativize($message['message']);
			}
		}

		foreach ($notFileSpecific as $error) {
			$lines[] = self::NOT_FILE_SPECIFIC . ' :: ' . $this->relativize($error);
		}

		if ($lines === [] && $exitCode !== 0) {
			throw new RuntimeException(
				"The analysis signalled failure (exit code $exitCode) but reported no errors:\n" . $output,
			);
		}

		sort($lines);

		return $lines;
	}

	// Scratch paths carry a per-run uniqid, so a raw message would never compare equal across two
	// scenarios - and, more importantly, would make an assertion failure unreadable.
	private function relativize(string $value): string
	{
		$relative = str_replace($this->sourceDir . '/', '', $value);

		return strpos($relative, $this->projectRoot) === false
			? $relative
			: str_replace($this->projectRoot . '/', '', $relative);
	}

}
