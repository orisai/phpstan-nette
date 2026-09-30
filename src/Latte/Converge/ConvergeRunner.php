<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Converge;

use function array_key_exists;
use function array_merge;
use function count;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function getcwd;
use function getenv;
use function getmypid;
use function in_array;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function proc_close;
use function proc_open;
use function rename;
use function sha1;
use function sha1_file;
use function sprintf;
use function stream_get_contents;
use function strncmp;
use function substr;

final class ConvergeRunner
{

	public const DEFAULT_MAX_RUNS = 6;

	private const USAGE = 'Usage: latte-converge [--max-runs=<n>] [--store=<dir>] [--prune] [--phpstan=<path>] analyse [<phpstan options and paths>]';

	private string $php;

	private string $phpstan;

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;

	private bool $stdoutIsTty;

	private string $cacheDirectory;

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
		string $cacheDirectory
	)
	{
		$this->php = $php;
		$this->phpstan = $phpstan;
		$this->stdout = $stdout;
		$this->stderr = $stderr;
		$this->stdoutIsTty = $stdoutIsTty;
		$this->cacheDirectory = $cacheDirectory;
	}

	/**
	 * @param list<string> $arguments
	 */
	public function run(array $arguments): int
	{
		$maxRuns = self::DEFAULT_MAX_RUNS;
		$prune = false;
		$store = null;
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
			} elseif (strncmp($argument, '--store=', 8) === 0) {
				$store = self::absolute(substr($argument, 8));
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

		$configurationOptions = self::configurationOptions($phpstanArguments);

		if ($store === null) {
			$cacheFile = $this->cacheFile($configurationOptions);
			$cached = self::cachedStore($cacheFile);
			if ($cached !== null) {
				$store = $cached['store'];
			} else {
				$dump = $this->execute(array_merge(['dump-parameters', '--json'], $configurationOptions), []);
				if ($dump['exitCode'] !== 0) {
					fwrite($this->stdout, $dump['stdout']);

					return $dump['exitCode'];
				}

				$parameters = json_decode($dump['stdout'], true);
				$store = self::storePath($parameters);
				self::cacheStore($cacheFile, $parameters, $store);
			}

			if ($store === null) {
				$once = $this->execute($phpstanArguments, []);
				fwrite($this->stdout, $once['stdout']);

				return $once['exitCode'];
			}
		}

		if ($prune) {
			$clear = $this->execute(array_merge(['clear-result-cache'], $configurationOptions), []);
			fwrite($this->stderr, $clear['stdout']);
			if ($clear['exitCode'] !== 0) {
				return $clear['exitCode'];
			}
		}

		$run = 0;
		while (true) {
			$run++;
			$before = StoreDigest::of($store);
			$result = $this->execute(
				$phpstanArguments,
				$prune && $run === 1 ? [StoreChangeSignal::PRUNE_ENVIRONMENT_VARIABLE => '1'] : [],
			);
			$changed = StoreDigest::of($store) !== $before;

			if ($result['exitCode'] >= 128 || !$changed) {
				fwrite($this->stdout, $result['stdout']);

				return $result['exitCode'];
			}

			if ($run >= $maxRuns) {
				fwrite($this->stdout, $result['stdout']);
				fwrite($this->stderr, sprintf(
					"The Latte narrowing store still changed on run %d of at most %d (--max-runs).\n",
					$run,
					$maxRuns,
				));

				return $result['exitCode'] !== 0 ? $result['exitCode'] : 1;
			}

			fwrite($this->stderr, sprintf(
				"The Latte narrowing store changed, running the analysis again (run %d of at most %d).\n",
				$run + 1,
				$maxRuns,
			));
		}
	}

	private static function isAnalyseCommand(string $argument): bool
	{
		return $argument === 'analyse' || $argument === 'analyze';
	}

	private static function absolute(string $path): string
	{
		if (strncmp($path, '/', 1) === 0 || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1) {
			return $path;
		}

		return getcwd() . '/' . $path;
	}

	/**
	 * @param mixed $parameters
	 */
	private static function storePath($parameters): ?string
	{
		$latte = is_array($parameters) ? $parameters['orisai']['nette']['latte'] ?? null : null;
		if (!is_array($latte) || !is_array($latte['narrowing'] ?? null)) {
			return null;
		}

		$enabled = $latte['enabled'] ?? null;
		$narrowingEnabled = $latte['narrowing']['enabled'] ?? null;
		$storePath = $latte['narrowing']['storePath'] ?? null;
		if ($enabled !== true || $narrowingEnabled !== true || !is_string($storePath)) {
			return null;
		}

		return self::absolute($storePath);
	}

	/**
	 * @param list<string> $configurationOptions
	 */
	private function cacheFile(array $configurationOptions): string
	{
		return $this->cacheDirectory . '/orisai-latte-converge-'
			. sha1((string) json_encode([getcwd(), $this->php, $this->phpstan, $configurationOptions])) . '.json';
	}

	/**
	 * @return array{store: string|null}|null
	 */
	private static function cachedStore(string $cacheFile): ?array
	{
		$cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
		if (
			!is_array($cached)
			|| !array_key_exists('store', $cached)
			|| !(is_string($cached['store']) || $cached['store'] === null)
			|| !is_array($cached['files'] ?? null)
		) {
			return null;
		}

		foreach ($cached['files'] as $file => $hash) {
			if (!is_string($file) || !is_file($file) || sha1_file($file) !== $hash) {
				return null;
			}
		}

		return ['store' => $cached['store']];
	}

	/**
	 * @param mixed $parameters
	 */
	private static function cacheStore(string $cacheFile, $parameters, ?string $store): void
	{
		$configFiles = is_array($parameters) ? $parameters['allConfigFiles'] ?? null : null;
		if (!is_array($configFiles) || $configFiles === []) {
			return;
		}

		$files = [];
		foreach ($configFiles as $file) {
			if (is_string($file) && strncmp($file, 'phar://', 7) === 0) {
				continue;
			}

			if (!is_string($file) || !is_file($file)) {
				return;
			}

			$files[$file] = sha1_file($file);
		}

		$temporary = $cacheFile . '.' . getmypid() . '.tmp';
		if (@file_put_contents($temporary, json_encode(['store' => $store, 'files' => $files])) !== false) {
			@rename($temporary, $cacheFile);
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
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $this->stderr],
			$pipes,
			null,
			$environment === [] ? null : array_merge(getenv(), $environment),
		);
		if (!is_resource($process)) {
			fwrite($this->stderr, "Cannot start PHPStan.\n");

			return ['exitCode' => 1, 'stdout' => ''];
		}

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
