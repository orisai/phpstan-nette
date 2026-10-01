<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Symfony\Component\Process\Process;
use function dirname;
use function explode;
use function implode;
use function rtrim;
use function sort;
use function str_replace;
use const PHP_BINARY;

final class LatteIntegrationSpawn
{

	private const FIXTURES = __DIR__ . '/../Integration/Latte/Integration/Fixtures';

	/**
	 * @return array{output: string, exitCode: int|null}
	 */
	public static function analyse(string $tmpDir): array
	{
		$projectRoot = dirname(__DIR__, 2);
		$config = LattePhpstanConfig::create(self::FIXTURES . '/integration.neon', [self::FIXTURES], $tmpDir);

		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-c',
				$config->getConfigPath(),
			],
			$projectRoot,
		);
		$process->setTimeout(null);
		$process->run();

		return [
			'output' => self::normalize($process->getOutput(), $projectRoot),
			'exitCode' => $process->getExitCode(),
		];
	}

	private static function normalize(string $raw, string $projectRoot): string
	{
		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $raw)) as $line) {
			$line = rtrim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		if ($lines === []) {
			return "(no errors)\n";
		}

		sort($lines);

		return implode("\n", $lines) . "\n";
	}

}
