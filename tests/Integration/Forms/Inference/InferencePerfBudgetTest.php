<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Inference;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use function dirname;
use function hrtime;
use function max;
use function sort;
use function sprintf;
use const PHP_BINARY;

final class InferencePerfBudgetTest extends FormShapeTestCase
{

	private const MAX_ON_OVER_OFF = 2.0;

	private const NOISE_FLOOR_SECONDS = 0.5;

	public function testSeamOnStaysWithinSmallFactorOfOff(): void
	{
		$projectRoot = dirname(__DIR__, 4);

		$off = $this->coldMedianSeconds(
			$projectRoot,
			dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/perf-off.neon',
		);
		$on = $this->coldMedianSeconds(
			$projectRoot,
			dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/perf-on.neon',
		);

		$ceiling = self::MAX_ON_OVER_OFF * max($off, self::NOISE_FLOOR_SECONDS);
		self::assertLessThan(
			$ceiling,
			$on,
			sprintf(
				'Inference ON cold analysis (%.4fs) must stay within %.1fx of OFF (%.4fs); ceiling %.4fs',
				$on,
				self::MAX_ON_OVER_OFF,
				$off,
				$ceiling,
			),
		);
	}

	private function coldMedianSeconds(string $projectRoot, string $config): float
	{
		$isolated = IsolatedPhpstanConfig::create($config);

		try {
			$cold = [];
			for ($i = 0; $i < 3; $i++) {
				FileSystem::delete($isolated->getTmpDir() . '/form-shape-cache');
				$this->clearResultCache($projectRoot, $isolated->getConfigPath());

				$start = hrtime(true);
				$this->analyse($projectRoot, $isolated->getConfigPath());
				$cold[] = (hrtime(true) - $start) / 1e9;
			}

			sort($cold);

			return $cold[1];
		} finally {
			$isolated->cleanup();
		}
	}

	private function clearResultCache(string $projectRoot, string $config): void
	{
		$process = new Process(
			[PHP_BINARY, $projectRoot . '/vendor/bin/phpstan', 'clear-result-cache', '-c', $config],
			$projectRoot,
		);
		$process->setTimeout(600.0);
		$process->mustRun();
	}

	private function analyse(string $projectRoot, string $config): void
	{
		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/vendor/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-c',
				$config,
				dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/Type',
			],
			$projectRoot,
		);
		$process->setTimeout(600.0);
		$process->run();
	}

}
