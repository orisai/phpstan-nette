<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Benchmark;

use Generator;
use LogicException;
use PhpBench\Benchmark\Metadata\Annotations\AfterMethods;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\OutputTimeUnit;
use PhpBench\Benchmark\Metadata\Annotations\ParamProviders;
use PhpBench\Benchmark\Metadata\Annotations\RetryThreshold;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_merge;
use function dirname;
use function in_array;
use function sprintf;
use function substr;
use const PHP_BINARY;

/**
 * @Revs(1)
 * @Iterations(3)
 * @RetryThreshold(3.5)
 * @OutputTimeUnit("seconds", precision=2)
 * @BeforeMethods("setUpConfig")
 * @AfterMethods("removeConfig")
 */
final class InferenceSeamBench
{

	private const FIXTURES = __DIR__ . '/../Unit/Forms/Inference/Fixtures';

	private IsolatedPhpstanConfig $config;

	/**
	 * @ParamProviders("provideSeam")
	 */
	public function benchColdAnalysis(): void
	{
		$process = $this->phpstan([
			'analyse',
			'--no-progress',
			'--level=8',
			'--error-format=raw',
			self::FIXTURES . '/Type',
		]);
		if (!in_array($process->getExitCode(), [0, 1], true)) {
			throw new LogicException(sprintf(
				'The analysis crashed with exit code %s: %s',
				$process->getExitCode() ?? 'null',
				substr($process->getOutput() . $process->getErrorOutput(), 0, 500),
			));
		}
	}

	/**
	 * @return Generator<string, array{config: string}>
	 */
	public function provideSeam(): Generator
	{
		yield 'seam: off' => ['config' => 'perf-off.neon'];
		yield 'seam: on' => ['config' => 'perf-on.neon'];
	}

	/**
	 * @param array{config: string} $params
	 */
	public function setUpConfig(array $params): void
	{
		$this->config = IsolatedPhpstanConfig::create(self::FIXTURES . '/' . $params['config']);
		$this->phpstan(['clear-result-cache'])->mustRun();
	}

	public function removeConfig(): void
	{
		$this->config->cleanup();
	}

	/**
	 * @param list<string> $arguments
	 */
	private function phpstan(array $arguments): Process
	{
		$projectRoot = dirname(__DIR__, 2);
		$process = new Process(
			array_merge(
				[PHP_BINARY, $projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan'],
				$arguments,
				['-c', $this->config->getConfigPath()],
			),
			$projectRoot,
		);
		$process->setTimeout(600.0);
		$process->run();

		return $process;
	}

}
