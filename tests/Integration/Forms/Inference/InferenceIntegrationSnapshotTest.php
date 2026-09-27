<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Inference;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use function dirname;
use function explode;
use function implode;
use function rtrim;
use function sort;
use function str_replace;
use const PHP_BINARY;

final class InferenceIntegrationSnapshotTest extends FormShapeTestCase
{

	public function testIntegrationSnapshot(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$isolated = IsolatedPhpstanConfig::create(
			dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/integration-snapshot.neon',
		);

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$projectRoot . '/vendor/bin/phpstan',
					'analyse',
					'--no-progress',
					'--level=8',
					'--error-format=raw',
					'-c',
					$isolated->getConfigPath(),
					dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/Integration.php',
				],
				$projectRoot,
			);
			$process->run();

			$actual = $this->normalize($process->getOutput(), $projectRoot);
			$expected = $this->normalize(
				FileSystem::read(dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/Integration.expected'),
				$projectRoot,
			);

			self::assertSame($expected, $actual);
		} finally {
			$isolated->cleanup();
		}
	}

	private function normalize(string $raw, string $projectRoot): string
	{
		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $raw)) as $line) {
			$line = rtrim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		sort($lines);

		return implode("\n", $lines) . "\n";
	}

}
