<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Benchmark;

use Generator;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use PhpBench\Benchmark\Metadata\Annotations\AfterMethods;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\OutputTimeUnit;
use PhpBench\Benchmark\Metadata\Annotations\ParamProviders;
use PhpBench\Benchmark\Metadata\Annotations\RetryThreshold;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeCorpusPass;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @Revs(3)
 * @Iterations(3)
 * @RetryThreshold(3.5)
 * @OutputTimeUnit("milliseconds")
 * @BeforeMethods("setUpCache")
 * @AfterMethods("removeCaches")
 */
final class FormShapeCacheBench
{

	private FormShapeCorpusPass $corpus;

	/** @var list<string> */
	private array $dirs = [];

	private FormShapeCache $warmCache;

	/**
	 * @ParamProviders("provideCold")
	 */
	public function benchColdPass(): void
	{
		$this->corpus->analyseCorpus(new FormShapeCache($this->freshDir()));
	}

	/**
	 * @ParamProviders("provideWarm")
	 */
	public function benchWarmPass(): void
	{
		$this->corpus->analyseCorpus($this->warmCache);
	}

	/**
	 * @return Generator<string, array{warm: bool}>
	 */
	public function provideCold(): Generator
	{
		yield 'full corpus' => ['warm' => false];
	}

	/**
	 * @return Generator<string, array{warm: bool}>
	 */
	public function provideWarm(): Generator
	{
		yield 'full corpus' => ['warm' => true];
	}

	/**
	 * @param array{warm: bool} $params
	 */
	public function setUpCache(array $params): void
	{
		$this->corpus = new FormShapeCorpusPass();
		$this->corpus->bootContainer();

		if ($params['warm']) {
			$this->warmCache = new FormShapeCache($this->freshDir());
			$this->corpus->analyseCorpus($this->warmCache);
		}
	}

	public function removeCaches(): void
	{
		foreach ($this->dirs as $dir) {
			FileSystem::delete($dir);
		}

		$this->dirs = [];
	}

	private function freshDir(): string
	{
		$dir = sys_get_temp_dir() . '/orisai-form-shape-bench-' . uniqid('', true);
		$this->dirs[] = $dir;

		return $dir;
	}

}
