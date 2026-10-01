<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Converge;

use function array_merge;
use function bin2hex;
use function count;
use function fclose;
use function file_get_contents;
use function fwrite;
use function getenv;
use function in_array;
use function is_file;
use function is_resource;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function sprintf;
use function stream_get_contents;
use function strncmp;
use function substr;
use function trim;
use function unlink;

final class ConvergeRunner
{

	public const DEFAULT_MAX_RUNS = 6;

	private const USAGE = 'Usage: latte-converge [--max-runs=<n>] [--prune] [--phpstan=<path>] analyse [<phpstan options and paths>]';

	private string $php;

	private string $phpstan;

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;

	private bool $stdoutIsTty;

	private string $temporaryDirectory;

	/**
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	public function __construct(
		string $php,
		string $phpstan,
		$stdout,
		$stderr,
		bool $stdoutIsTty,
		string $temporaryDirectory
	)
	{
		$this->php = $php;
		$this->phpstan = $phpstan;
		$this->stdout = $stdout;
		$this->stderr = $stderr;
		$this->stdoutIsTty = $stdoutIsTty;
		$this->temporaryDirectory = $temporaryDirectory;
	}

	/**
	 * @param list<string> $arguments
	 */
	public function run(array $arguments): int
	{
		$maxRuns = self::DEFAULT_MAX_RUNS;
		$prune = false;
		$phpstanArguments = [];
		foreach ($arguments as $argument) {
			if ($argument === '--prune') {
				$prune = true;
			} elseif (strncmp($argument, '--max-runs=', 11) === 0) {
				$value = substr($argument, 11);
				if (preg_match('~^[1-9][0-9]*$~', $value) !== 1) {
					return $this->usageError(sprintf('--max-runs expects a positive integer, "%s" given.', $value));
				}

				$maxRuns = (int) $value;
			} elseif (strncmp($argument, '--phpstan=', 10) === 0) {
				$this->phpstan = substr($argument, 10);
			} else {
				$phpstanArguments[] = $argument;
			}
		}

		if ($phpstanArguments === [] || !self::isAnalyseCommand($phpstanArguments[0])) {
			return $this->usageError('The first PHPStan argument must be the analyse command.');
		}

		if (
			$this->stdoutIsTty
			&& !in_array('--ansi', $phpstanArguments, true)
			&& !in_array('--no-ansi', $phpstanArguments, true)
		) {
			$phpstanArguments[] = '--ansi';
		}

		$directory = $this->temporaryDirectory . '/orisai-latte-converge-' . bin2hex(random_bytes(8));
		if (!@mkdir($directory, 0700)) {
			fwrite($this->stderr, sprintf("Cannot create a private directory in %s.\n", $this->temporaryDirectory));

			return 1;
		}

		try {
			if ($prune) {
				$clear = $this->execute(
					array_merge(['clear-result-cache'], self::configurationOptions($phpstanArguments)),
					[],
				);
				fwrite($this->stderr, $clear['stdout']);
				if ($clear['exitCode'] !== 0) {
					return $clear['exitCode'];
				}
			}

			return $this->converge($phpstanArguments, $directory . '/report', $prune, $maxRuns);
		} finally {
			self::remove($directory . '/report');
			self::remove($directory . '/report' . StoreChangeSignal::PRUNE_EVALUATED_SUFFIX);
			rmdir($directory);
		}
	}

	/**
	 * @param list<string> $phpstanArguments
	 */
	private function converge(array $phpstanArguments, string $report, bool $prune, int $maxRuns): int
	{
		$environment = [StoreChangeSignal::REPORT_ENVIRONMENT_VARIABLE => $report];
		if ($prune) {
			$environment[StoreChangeSignal::PRUNE_ENVIRONMENT_VARIABLE] = '1';
		}

		$pruneEvaluated = false;
		$run = 0;
		while (true) {
			$run++;
			self::remove($report);
			self::remove($report . StoreChangeSignal::PRUNE_EVALUATED_SUFFIX);

			$result = $this->execute($phpstanArguments, $environment);
			$change = is_file($report) ? trim((string) file_get_contents($report)) : null;
			$pruneEvaluated = $pruneEvaluated || is_file($report . StoreChangeSignal::PRUNE_EVALUATED_SUFFIX);

			if ($result['exitCode'] >= 128 || $change === null) {
				break;
			}

			if ($run >= $maxRuns) {
				fwrite($this->stdout, $result['stdout']);
				fwrite($this->stderr, sprintf(
					"The Latte narrowing store still changed on run %d of at most %d (--max-runs): %s\n",
					$run,
					$maxRuns,
					$change,
				));
				$this->reportUnevaluatedPrune($prune, $pruneEvaluated);

				return $result['exitCode'] !== 0 ? $result['exitCode'] : 1;
			}

			fwrite($this->stderr, sprintf(
				"%s\nThe Latte narrowing store changed, running the analysis again (run %d of at most %d).\n",
				$change,
				$run + 1,
				$maxRuns,
			));
		}

		fwrite($this->stdout, $result['stdout']);
		$this->reportUnevaluatedPrune($prune, $pruneEvaluated);

		return $result['exitCode'];
	}

	private static function isAnalyseCommand(string $argument): bool
	{
		return $argument === 'analyse' || $argument === 'analyze';
	}

	private static function remove(string $path): void
	{
		if (is_file($path)) {
			unlink($path);
		}
	}

	private function reportUnevaluatedPrune(bool $prune, bool $pruneEvaluated): void
	{
		if ($prune && !$pruneEvaluated) {
			fwrite($this->stderr, "Nothing was pruned (narrowing disabled or store missing).\n");
		}
	}

	/**
	 * @param list<string> $arguments
	 * @return list<string>
	 */
	private static function configurationOptions(array $arguments): array
	{
		$options = [];
		for ($i = 0; $i < count($arguments); $i++) {
			$argument = $arguments[$i];
			if (preg_match('~^(-c|--configuration|-a|--autoload-file|--memory-limit)$~', $argument) === 1) {
				if (isset($arguments[$i + 1])) {
					$options[] = $argument;
					$options[] = $arguments[++$i];
				}

				continue;
			}

			if (
				preg_match(
					'~^(-c.+|-a.+|--configuration=.*|--autoload-file=.*|--memory-limit=.*|--debug)$~',
					$argument,
				) === 1
			) {
				$options[] = $argument;
			}
		}

		return $options;
	}

	/**
	 * @param list<string> $arguments
	 * @param array<string, string> $environment
	 * @return array{exitCode: int, stdout: string}
	 */
	private function execute(array $arguments, array $environment): array
	{
		$process = proc_open(
			array_merge([$this->php, $this->phpstan], $arguments),
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $this->stderr],
			$pipes,
			null,
			$environment === [] ? null : array_merge(getenv(), $environment),
		);
		if (!is_resource($process)) {
			fwrite($this->stderr, "Cannot start PHPStan.\n");

			return ['exitCode' => 1, 'stdout' => ''];
		}

		fclose($pipes[0]);
		$stdout = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		return ['exitCode' => proc_close($process), 'stdout' => $stdout];
	}

	private function usageError(string $message): int
	{
		fwrite($this->stderr, $message . "\n" . self::USAGE . "\n");

		return 2;
	}

}
