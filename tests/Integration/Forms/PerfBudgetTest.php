<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function array_filter;
use function array_values;
use function dirname;
use function glob;
use function hrtime;
use function is_array;
use function is_dir;
use function sort;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

final class PerfBudgetTest extends FormShapeTestCase
{

	// Observed: cold full-corpus pass ≈ 1.30s, warm median ≈ 0.31s (~4x speedup, stable ±0.005s). Budget ≈ 3x observed warm, below cold, so a slow CI box cannot flake yet a real regression still trips.
	private const WARM_BUDGET_SECONDS = 1.0;

	/** @var list<string> */
	private array $dirs = [];

	protected function tearDown(): void
	{
		foreach ($this->dirs as $dir) {
			if (is_dir($dir)) {
				FileSystem::delete($dir);
			}
		}

		$this->dirs = [];
	}

	private function freshDir(): string
	{
		$dir = sys_get_temp_dir() . '/perf-cache-' . uniqid('', true);
		$this->dirs[] = $dir;

		return $dir;
	}

	/** @return list<string> */
	private function corpus(): array
	{
		$files = glob(dirname(__DIR__, 2) . '/Doubles/Forms/MatrixAssert/*.php');

		return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
	}

	private function pass(FormShapeCache $cache): void
	{
		foreach ($this->corpus() as $file) {
			$this->captureFixtureShapes($file, $cache);
		}
	}

	public function testWarmIsFasterThanColdAndWithinBudget(): void
	{
		$sharedDir = $this->freshDir();
		$sharedCache = new FormShapeCache($sharedDir);

		$coldStart = hrtime(true);
		$this->pass($sharedCache);
		$cold = (hrtime(true) - $coldStart) / 1e9;

		// Median of a few warm iterations to dampen jitter.
		$warmTimes = [];
		for ($i = 0; $i < 5; $i++) {
			$start = hrtime(true);
			$this->pass($sharedCache);
			$warmTimes[] = (hrtime(true) - $start) / 1e9;
		}

		sort($warmTimes);
		$warm = $warmTimes[2];

		self::assertLessThan(
			$cold,
			$warm,
			sprintf('warm pass (%.4fs) must be faster than cold pass (%.4fs)', $warm, $cold),
		);
		self::assertLessThan(
			self::WARM_BUDGET_SECONDS,
			$warm,
			sprintf('warm pass (%.4fs) exceeded budget %.2fs', $warm, self::WARM_BUDGET_SECONDS),
		);
	}

}
