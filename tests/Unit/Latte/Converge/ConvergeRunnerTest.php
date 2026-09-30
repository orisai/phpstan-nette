<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Converge;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Latte\Converge\ConvergeRunner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function fopen;
use function getmypid;
use function putenv;
use function rewind;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tmpfile;
use function uniqid;
use const PHP_BINARY;

final class ConvergeRunnerTest extends BaseTestCase
{

	private const FAKE = __DIR__ . '/Fixtures/fake-phpstan.php';

	private string $dir;

	private string $scenario;

	private string $store;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/latte-converge-test-' . getmypid() . '-' . uniqid('', true);
		$this->scenario = $this->dir . '/scenario.json';
		$this->store = $this->dir . '/store';
		FileSystem::createDir($this->store);
		putenv('FAKE_PHPSTAN_SCENARIO=' . $this->scenario);
	}

	protected function tearDown(): void
	{
		putenv('FAKE_PHPSTAN_SCENARIO');
		FileSystem::delete($this->dir);
		parent::tearDown();
	}

	public function testRerunsWhileTheStoreChangesWithTheCallersArgumentsUnmodified(): void
	{
		$this->scenario([
			['exitCode' => 1, 'stdout' => "run 1\n", 'write' => ['LatteSlice_a.php' => '1']],
			['exitCode' => 1, 'stdout' => "run 2\n", 'write' => ['LatteSlice_b.php' => '1']],
			['exitCode' => 0, 'stdout' => "run 3\n", 'stderr' => 'progress', 'write' => ['LatteSlice_a.php' => '1']],
		]);

		$caller = ['analyse', '-c', 'phpstan.neon', '--error-format=table', 'src'];
		$result = $this->converge($caller);

		self::assertSame(0, $result['exitCode']);
		self::assertSame("run 3\n", $result['stdout']);
		self::assertSame(
			"The Latte narrowing store changed, running the analysis again (run 2 of at most 6).\n"
				. "The Latte narrowing store changed, running the analysis again (run 3 of at most 6).\n"
				. 'progress',
			$result['stderr'],
		);
		self::assertSame([
			['dump-parameters', '--json', '-c', 'phpstan.neon'],
			$caller,
			$caller,
			$caller,
		], $this->argumentVectors());
	}

	public function testAFindingNextToAStoreChangeIsPrintedOnlyFromTheSettledRun(): void
	{
		$this->scenario([
			['exitCode' => 1, 'stdout' => "stale finding\n", 'write' => ['LatteSlice_a.php' => '1']],
			['exitCode' => 1, 'stdout' => "finding\n"],
		]);

		$result = $this->converge(['analyse']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("finding\n", $result['stdout']);
	}

	public function testADeletedSliceCountsAsAChange(): void
	{
		FileSystem::write($this->store . '/LatteSlice_gone.php', 'x');
		$this->scenario([
			['exitCode' => 1, 'stdout' => "1\n", 'delete' => ['LatteSlice_gone.php']],
			['exitCode' => 0, 'stdout' => "2\n"],
		]);

		self::assertSame("2\n", $this->converge(['analyse'])['stdout']);
	}

	public function testTheRunCapPrintsTheLastRunAndFails(): void
	{
		$this->scenario([
			['exitCode' => 0, 'stdout' => "1\n", 'write' => ['LatteSlice_a.php' => '1']],
			['exitCode' => 0, 'stdout' => "2\n", 'write' => ['LatteSlice_a.php' => '2']],
		]);

		$result = $this->converge(['--max-runs=2', 'analyse']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("2\n", $result['stdout']);
		self::assertSame(
			"The Latte narrowing store changed, running the analysis again (run 2 of at most 2).\n"
				. "The Latte narrowing store still changed on run 2 of at most 2 (--max-runs).\n",
			$result['stderr'],
		);
		self::assertCount(3, $this->calls());
	}

	public function testTheRunCapKeepsANonZeroExitCode(): void
	{
		$this->scenario([['exitCode' => 3, 'stdout' => '', 'write' => ['LatteSlice_a.php' => '1']]]);

		self::assertSame(3, $this->converge(['--max-runs=1', 'analyse'])['exitCode']);
	}

	public function testACrashIsForwardedUnchanged(): void
	{
		$this->scenario([['exitCode' => 255, 'stdout' => 'Fatal error', 'stderr' => 'trace']]);

		$result = $this->converge(['analyse']);

		self::assertSame(255, $result['exitCode']);
		self::assertSame('Fatal error', $result['stdout']);
		self::assertSame('trace', $result['stderr']);
		self::assertCount(2, $this->calls());
	}

	public function testASignalStopsTheLoopEvenWhenTheStoreChanged(): void
	{
		$this->scenario([['exitCode' => 130, 'stdout' => '', 'write' => ['LatteSlice_a.php' => '1']]]);

		self::assertSame(130, $this->converge(['analyse'])['exitCode']);
		self::assertCount(2, $this->calls());
	}

	public function testAGeneratedBaselineKeepsExitCodeZero(): void
	{
		$this->scenario([['exitCode' => 0, 'stdout' => "Baseline generated with 3 errors.\n"]]);

		$result = $this->converge(['analyse', '-b']);

		self::assertSame(0, $result['exitCode']);
		self::assertSame("Baseline generated with 3 errors.\n", $result['stdout']);
		self::assertSame(['analyse', '-b'], $this->argumentVectors()[1]);
	}

	public function testAnAbsentStoreDirectoryRunsOnce(): void
	{
		FileSystem::delete($this->store);
		$this->scenario([['exitCode' => 0, 'stdout' => "ok\n"]]);

		self::assertSame("ok\n", $this->converge(['analyse'])['stdout']);
		self::assertCount(2, $this->calls());
	}

	public function testDisabledNarrowingRunsOnceWithoutDigest(): void
	{
		$this->scenario(
			[['exitCode' => 0, 'stdout' => "ok\n", 'write' => ['LatteSlice_a.php' => '1']]],
			['latte' => ['enabled' => true, 'narrowing' => ['enabled' => false, 'storePath' => $this->store]]],
		);

		self::assertSame("ok\n", $this->converge(['analyse'])['stdout']);
		self::assertCount(2, $this->calls());
	}

	public function testDisabledLatteRunsOnce(): void
	{
		$this->scenario(
			[['exitCode' => 0, 'stdout' => "ok\n", 'write' => ['LatteSlice_a.php' => '1']]],
			['latte' => ['enabled' => false, 'narrowing' => ['enabled' => true, 'storePath' => $this->store]]],
		);

		self::assertSame("ok\n", $this->converge(['analyse'])['stdout']);
		self::assertCount(2, $this->calls());
	}

	public function testStoreOptionSkipsDumpParameters(): void
	{
		$this->scenario([
			['exitCode' => 0, 'stdout' => "1\n", 'write' => ['LatteSlice_a.php' => '1']],
			['exitCode' => 0, 'stdout' => "2\n"],
		]);

		$result = $this->converge(['--store=' . $this->store, 'analyse']);

		self::assertSame("2\n", $result['stdout']);
		self::assertSame([['analyse'], ['analyse']], $this->argumentVectors());
	}

	public function testDumpParametersFailureIsForwarded(): void
	{
		$this->scenario([], null, ['exitCode' => 1, 'stdout' => "Invalid configuration\n", 'stderr' => 'details']);

		$result = $this->converge(['analyse', '--configuration=broken.neon']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("Invalid configuration\n", $result['stdout']);
		self::assertSame('details', $result['stderr']);
		self::assertSame([['dump-parameters', '--json', '--configuration=broken.neon']], $this->argumentVectors());
	}

	public function testAnsiIsAppendedOnlyForATerminalWithoutAnExplicitFlag(): void
	{
		$this->scenario([['exitCode' => 0, 'stdout' => '']]);

		$this->converge(['analyse'], true);
		$this->converge(['analyse', '--no-ansi'], true);
		$this->converge(['analyse', '--ansi'], true);
		$this->converge(['analyse'], false);

		self::assertSame([
			['dump-parameters', '--json'],
			['analyse', '--ansi'],
			['dump-parameters', '--json'],
			['analyse', '--no-ansi'],
			['dump-parameters', '--json'],
			['analyse', '--ansi'],
			['dump-parameters', '--json'],
			['analyse'],
		], $this->argumentVectors());
	}

	public function testPruneClearsTheResultCacheAndPrunesOnTheFirstRunOnly(): void
	{
		$this->scenario([
			['exitCode' => 1, 'stdout' => "1\n", 'write' => ['LatteSlice_a.php' => '1']],
			['exitCode' => 0, 'stdout' => "2\n"],
		]);

		$result = $this->converge(
			['--prune', 'analyse', '-c', 'phpstan.neon', '--memory-limit=2G', '-afile.php', '--level=8'],
		);

		self::assertSame(0, $result['exitCode']);
		self::assertSame("2\n", $result['stdout']);
		self::assertStringStartsWith("cleared\n", $result['stderr']);
		$analyse = ['analyse', '-c', 'phpstan.neon', '--memory-limit=2G', '-afile.php', '--level=8'];
		self::assertSame([
			['arguments' => ['dump-parameters', '--json', '-c', 'phpstan.neon', '--memory-limit=2G', '-afile.php'], 'prune' => false],
			['arguments' => ['clear-result-cache', '-c', 'phpstan.neon', '--memory-limit=2G', '-afile.php'], 'prune' => false],
			['arguments' => $analyse, 'prune' => '1'],
			['arguments' => $analyse, 'prune' => false],
		], $this->calls());
	}

	public function testTheStorePathIsCachedUntilAConfigurationFileChanges(): void
	{
		$config = $this->dir . '/phpstan.neon';
		FileSystem::write($config, 'parameters: {}');
		$this->scenario(
			[['exitCode' => 0, 'stdout' => "ok\n"]],
			null,
			null,
			[$config, 'phar://phpstan.phar/conf/bleedingEdge.neon'],
		);

		$this->converge(['analyse', '-c', $config]);
		$this->converge(['analyse', '-c', $config]);
		FileSystem::write($config, 'parameters: {level: 8}');
		$this->converge(['analyse', '-c', $config]);
		$this->converge(['analyse', '-c', 'other.neon']);

		self::assertSame([
			['dump-parameters', '--json', '-c', $config],
			['analyse', '-c', $config],
			['analyse', '-c', $config],
			['dump-parameters', '--json', '-c', $config],
			['analyse', '-c', $config],
			['dump-parameters', '--json', '-c', 'other.neon'],
			['analyse', '-c', 'other.neon'],
		], $this->argumentVectors());
	}

	public function testACachedDisabledNarrowingStillRunsOnce(): void
	{
		$config = $this->dir . '/phpstan.neon';
		FileSystem::write($config, 'parameters: {}');
		$this->scenario(
			[['exitCode' => 0, 'stdout' => "ok\n", 'write' => ['LatteSlice_a.php' => '1']]],
			['enabled' => false],
			null,
			[$config],
		);

		$this->converge(['analyse']);
		self::assertSame("ok\n", $this->converge(['analyse'])['stdout']);
		self::assertSame([['dump-parameters', '--json'], ['analyse'], ['analyse']], $this->argumentVectors());
	}

	public function testPhpstanPathCanBeOverridden(): void
	{
		$this->scenario([['exitCode' => 0, 'stdout' => 'ok']]);

		$runner = new ConvergeRunner(
			PHP_BINARY,
			'/nonexistent/phpstan',
			$this->memory(),
			$this->file(),
			false,
			$this->dir,
		);

		self::assertSame(0, $runner->run(['--phpstan=' . self::FAKE, 'analyse']));
		self::assertCount(2, $this->calls());
	}

	public function testRejectsAMissingAnalyseCommand(): void
	{
		$result = $this->converge(['src']);

		self::assertSame(2, $result['exitCode']);
		self::assertStringStartsWith('The first PHPStan argument must be the analyse command.', $result['stderr']);
	}

	public function testRejectsAnInvalidRunCap(): void
	{
		$result = $this->converge(['--max-runs=0', 'analyse']);

		self::assertSame(2, $result['exitCode']);
		self::assertStringStartsWith('--max-runs expects a positive integer, "0" given.', $result['stderr']);
	}

	/**
	 * @param list<string> $arguments
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function converge(array $arguments, bool $tty = false): array
	{
		$stdout = $this->memory();
		$stderr = $this->file();
		$exitCode = (new ConvergeRunner(PHP_BINARY, self::FAKE, $stdout, $stderr, $tty, $this->dir))->run($arguments);

		rewind($stdout);
		rewind($stderr);

		return [
			'exitCode' => $exitCode,
			'stdout' => (string) stream_get_contents($stdout),
			'stderr' => (string) stream_get_contents($stderr),
		];
	}

	/**
	 * @return resource
	 */
	private function memory()
	{
		$stream = fopen('php://memory', 'w+');
		self::assertNotFalse($stream);

		return $stream;
	}

	/**
	 * @return resource
	 */
	private function file()
	{
		$stream = tmpfile();
		self::assertNotFalse($stream);

		return $stream;
	}

	/**
	 * @param list<array<string, mixed>> $runs
	 * @param array<string, mixed>|null $latte
	 * @param array{exitCode: int, stdout: string, stderr: string}|null $dumpParameters
	 * @param list<string> $configFiles
	 */
	private function scenario(
		array $runs,
		?array $latte = null,
		?array $dumpParameters = null,
		array $configFiles = []
	): void
	{
		$latte ??= ['enabled' => true, 'narrowing' => ['enabled' => true, 'storePath' => $this->store]];
		FileSystem::write($this->scenario, Json::encode([
			'store' => $this->store,
			'run' => 0,
			'calls' => [],
			'runs' => $runs,
			'dumpParameters' => $dumpParameters ?? [
				'exitCode' => 0,
				'stdout' => Json::encode([
					'allConfigFiles' => $configFiles,
					'level' => 8,
					'orisai' => ['nette' => ['latte' => $latte]],
				]),
			],
		]));
	}

	/**
	 * @return list<array{arguments: list<string>, prune: string|false}>
	 */
	private function calls(): array
	{
		return Json::decode(FileSystem::read($this->scenario), Json::FORCE_ARRAY)['calls'];
	}

	/**
	 * @return list<list<string>>
	 */
	private function argumentVectors(): array
	{
		$vectors = [];
		foreach ($this->calls() as $call) {
			$vectors[] = $call['arguments'];
		}

		return $vectors;
	}

}
