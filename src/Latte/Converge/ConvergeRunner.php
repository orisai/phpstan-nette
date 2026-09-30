<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Converge;

use function array_merge;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_select;
use function stream_set_blocking;
use function strlen;
use function strncmp;
use function substr;

final class ConvergeRunner
{

	public const DEFAULT_MAX_RUNS = 6;

	private const STDOUT_CAPTURE = 1;

	private const STDOUT_FORWARD = 2;

	private const USAGE = 'Usage: latte-converge [--max-runs=<n>] [--phpstan=<path>] analyse [<phpstan options and paths>]';

	private string $php;

	private string $phpstan;

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;

	/**
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	public function __construct(string $php, string $phpstan, $stdout, $stderr)
	{
		$this->php = $php;
		$this->phpstan = $phpstan;
		$this->stdout = $stdout;
		$this->stderr = $stderr;
	}

	/**
	 * @param list<string> $arguments
	 */
	public function run(array $arguments): int
	{
		$maxRuns = self::DEFAULT_MAX_RUNS;
		$phpstanArguments = [];
		foreach ($arguments as $argument) {
			if (strncmp($argument, '--max-runs=', 11) === 0) {
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

		[$format, $jsonArguments] = self::withJsonFormat($phpstanArguments);

		$run = 0;
		while (true) {
			$run++;
			$result = $this->execute($jsonArguments, self::STDOUT_CAPTURE);

			$decoded = json_decode($result['stdout'], true);
			if (!is_array($decoded)) {
				fwrite($this->stdout, $result['stdout']);

				return $result['exitCode'] === 0 ? 1 : $result['exitCode'];
			}

			$storeChanged = self::storeChangedMessage($decoded);
			if ($storeChanged === null) {
				break;
			}

			if ($run >= $maxRuns) {
				fwrite($this->stderr, sprintf(
					"The Latte narrowing store did not settle within %d runs (--max-runs); the last run reported:\n%s\n",
					$maxRuns,
					$storeChanged,
				));

				return 1;
			}

			fwrite($this->stderr, sprintf(
				"The Latte narrowing store changed, running the analysis again (run %d of at most %d).\n",
				$run + 1,
				$maxRuns,
			));
		}

		if ($format === 'json') {
			fwrite($this->stdout, $result['stdout']);

			return $result['exitCode'];
		}

		return $this->execute($phpstanArguments, self::STDOUT_FORWARD)['exitCode'];
	}

	private static function isAnalyseCommand(string $argument): bool
	{
		return $argument === 'analyse' || $argument === 'analyze';
	}

	/**
	 * @param list<string> $arguments
	 * @return array{string|null, list<string>}
	 */
	private static function withJsonFormat(array $arguments): array
	{
		$format = null;
		$kept = [];
		for ($i = 0; $i < count($arguments); $i++) {
			$argument = $arguments[$i];
			if ($argument === '--error-format' && isset($arguments[$i + 1])) {
				$format = $arguments[++$i];

				continue;
			}

			if (strncmp($argument, '--error-format=', 15) === 0) {
				$format = substr($argument, 15);

				continue;
			}

			$kept[] = $argument;
		}

		$kept[] = '--error-format=json';

		return [$format, $kept];
	}

	/**
	 * @param array<mixed> $decoded
	 */
	private static function storeChangedMessage(array $decoded): ?string
	{
		if (!is_array($decoded['files'] ?? null)) {
			return null;
		}

		foreach ($decoded['files'] as $file) {
			if (!is_array($file) || !is_array($file['messages'] ?? null)) {
				continue;
			}

			foreach ($file['messages'] as $message) {
				if (
					is_array($message)
					&& ($message['identifier'] ?? null) === StoreChangeSignal::IDENTIFIER
					&& is_string($message['message'] ?? null)
				) {
					return $message['message'];
				}
			}
		}

		return null;
	}

	/**
	 * @param list<string> $arguments
	 * @param self::STDOUT_* $stdoutMode
	 * @return array{exitCode: int, stdout: string}
	 */
	private function execute(array $arguments, int $stdoutMode): array
	{
		$process = proc_open(
			array_merge([$this->php, $this->phpstan], $arguments),
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		if (!is_resource($process)) {
			fwrite($this->stderr, "Cannot start PHPStan.\n");

			return ['exitCode' => 1, 'stdout' => ''];
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$open = [1 => $pipes[1], 2 => $pipes[2]];
		while ($open !== []) {
			$read = $open;
			$write = null;
			$except = null;
			if (stream_select($read, $write, $except, null) === false) {
				break;
			}

			foreach ($read as $pipe) {
				$chunk = fread($pipe, 65536);
				if ($chunk !== false && strlen($chunk) > 0) {
					if ($pipe === $pipes[1] && $stdoutMode === self::STDOUT_CAPTURE) {
						$stdout .= $chunk;
					} elseif ($pipe === $pipes[1]) {
						fwrite($this->stdout, $chunk);
					} else {
						fwrite($this->stderr, $chunk);
					}
				}

				if (feof($pipe)) {
					fclose($pipe);
					unset($open[$pipe === $pipes[1] ? 1 : 2]);
				}
			}
		}

		return ['exitCode' => proc_close($process), 'stdout' => $stdout];
	}

	private function usageError(string $message): int
	{
		fwrite($this->stderr, $message . "\n" . self::USAGE . "\n");

		return 2;
	}

}
