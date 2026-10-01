<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Benchmark;

use Generator;
use LogicException;
use Nette\Utils\FileSystem;
use PhpBench\Benchmark\Metadata\Annotations\AfterMethods;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\OutputTimeUnit;
use PhpBench\Benchmark\Metadata\Annotations\ParamProviders;
use PhpBench\Benchmark\Metadata\Annotations\RetryThreshold;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\LatteIntegrationSpawn;
use function in_array;
use function sprintf;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @Revs(1)
 * @Iterations(2)
 * @RetryThreshold(3.5)
 * @OutputTimeUnit("seconds", precision=2)
 * @BeforeMethods("setUpTmpDir")
 * @AfterMethods("removeTmpDir")
 */
final class LatteSpawnBench
{

	private string $tmpDir;

	/**
	 * @ParamProviders("provideCold")
	 */
	public function benchColdSpawn(): void
	{
		$this->analyse();
	}

	/**
	 * @ParamProviders("provideWarm")
	 */
	public function benchWarmSpawn(): void
	{
		$this->analyse();
	}

	/**
	 * @return Generator<string, array{warm: bool}>
	 */
	public function provideCold(): Generator
	{
		yield 'latte ' . InstalledVersionsGuard::latteLine() => ['warm' => false];
	}

	/**
	 * @return Generator<string, array{warm: bool}>
	 */
	public function provideWarm(): Generator
	{
		yield 'latte ' . InstalledVersionsGuard::latteLine() => ['warm' => true];
	}

	/**
	 * @param array{warm: bool} $params
	 */
	public function setUpTmpDir(array $params): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/orisai-latte-spawn-bench-' . uniqid('', true);

		if ($params['warm']) {
			$this->analyse();
		}
	}

	public function removeTmpDir(): void
	{
		FileSystem::delete($this->tmpDir);
	}

	private function analyse(): void
	{
		$result = LatteIntegrationSpawn::analyse($this->tmpDir);
		if (!in_array($result['exitCode'], [0, 1], true)) {
			throw new LogicException(sprintf(
				'The spawn crashed with exit code %s: %s',
				$result['exitCode'] ?? 'none',
				substr($result['output'], 0, 500),
			));
		}
	}

}
