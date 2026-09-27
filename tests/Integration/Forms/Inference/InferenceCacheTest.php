<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Inference;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use function dirname;
use function preg_match;
use function sys_get_temp_dir;
use function uniqid;
use const PHP_BINARY;

final class InferenceCacheTest extends FormShapeTestCase
{

	/** @var list<string> */
	private array $tempDirs = [];

	protected function tearDown(): void
	{
		foreach ($this->tempDirs as $dir) {
			FileSystem::delete($dir);
		}

		$this->tempDirs = [];
	}

	public function testColdEqualsWarmForInferenceCarriers(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$isolated = IsolatedPhpstanConfig::create(
			dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/integration-snapshot.neon',
		);

		try {
			$cold = $this->analyse(
				$projectRoot,
				$isolated->getConfigPath(),
				dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/Type/Cooperation.php',
			);
			$warm = $this->analyse(
				$projectRoot,
				$isolated->getConfigPath(),
				dirname(__DIR__, 3) . '/Unit/Forms/Inference/Fixtures/Type/Cooperation.php',
			);

			self::assertSame($cold, $warm, 'warm seam cache must produce byte-identical analysis output');
		} finally {
			$isolated->cleanup();
		}
	}

	public function testFixtureContentChangeInvalidatesShape(): void
	{
		$cache = new FormShapeCache($this->freshDir());

		$first = $cache->remember('hash-before-edit', 'node', static fn (): FormShape => FormShape::empty('Before'));
		self::assertSame('Before', $first->getClassName());

		$afterEdit = $cache->remember('hash-after-edit', 'node', static fn (): FormShape => FormShape::empty('After'));
		self::assertSame('After', $afterEdit->getClassName());

		$staleServed = $cache->remember(
			'hash-before-edit',
			'node',
			static fn (): FormShape => FormShape::empty('Recomputed'),
		);
		self::assertSame('Before', $staleServed->getClassName(), 'original content hash still serves its own entry');
	}

	public function testCodeVersionIsDeterministicAndKeyembedsIt(): void
	{
		$cache = new FormShapeCache($this->freshDir());
		$analyzer = new FormShapeAnalyzer(
			new NodeContributionSummaryFactory($this->createCatalog($cache)),
			$cache,
			$this->createCallees($cache),
		);

		$reflection = new ReflectionMethod(FormShapeAnalyzer::class, 'codeVersion');
		$reflection->setAccessible(true);

		$first = $reflection->invoke($analyzer);
		$second = $reflection->invoke($analyzer);

		self::assertSame($first, $second, 'codeVersion() must be deterministic within a process');
		self::assertSame(
			1,
			preg_match('/^[0-9a-f]{40}$/', $first),
			'codeVersion() must be a sha1 of the Forms code tree',
		);

		$cache->remember(
			'same-file-hash',
			'v2|' . $first . '|fnKey#form',
			static fn (): FormShape => FormShape::empty('CurrentCode'),
		);
		$recomputed = $cache->remember(
			'same-file-hash',
			'v2|deadbeef|fnKey#form',
			static fn (): FormShape => FormShape::empty('ChangedCode'),
		);
		self::assertSame(
			'ChangedCode',
			$recomputed->getClassName(),
			'a different code-version key must recompute, never serve the stale entry',
		);
	}

	private function analyse(string $projectRoot, string $config, string $fixture): string
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
				$fixture,
			],
			$projectRoot,
		);
		$process->run();

		return $process->getOutput();
	}

	private function freshDir(): string
	{
		$dir = sys_get_temp_dir() . '/form-shape-cache-' . uniqid('', true);
		$this->tempDirs[] = $dir;

		return $dir;
	}

}
