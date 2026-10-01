<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Converge;

use Generator;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Latte\Converge\ConvergeRunner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_unique;
use function chmod;
use function fopen;
use function function_exists;
use function getmypid;
use function glob;
use function posix_geteuid;
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

	private const CHANGED = 'The Latte narrowing store changed for 1 including template: a.latte.';

	private string $dir;

	private string $scenario;

	private string $temp;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/latte-converge-test-' . getmypid() . '-' . uniqid('', true);
		$this->scenario = $this->dir . '/scenario.json';
		$this->temp = $this->dir . '/tmp';
		FileSystem::createDir($this->temp);
		putenv('FAKE_PHPSTAN_SCENARIO=' . $this->scenario);
	}

	protected function tearDown(): void
	{
		putenv('FAKE_PHPSTAN_SCENARIO');
		FileSystem::delete($this->dir);
		parent::tearDown();
	}

	public function testRerunsWhileTheWriterReportsAChangeWithTheCallersArgumentsUnmodified(): void
	{
		$this->scenario([
			['exitCode' => 1, 'stdout' => "run 1\n", 'report' => self::CHANGED],
			['exitCode' => 1, 'stdout' => "run 2\n", 'report' => self::CHANGED],
			['exitCode' => 0, 'stdout' => "run 3\n", 'stderr' => 'progress'],
		]);

		$caller = ['analyse', '-c', 'phpstan.neon', '--error-format=table', 'src'];
		$result = $this->converge($caller);

		self::assertSame(0, $result['exitCode']);
		self::assertSame("run 3\n", $result['stdout']);
		self::assertSame(
			self::CHANGED . "\nThe Latte narrowing store changed, running the analysis again (run 2 of at most 6).\n"
				. self::CHANGED . "\nThe Latte narrowing store changed, running the analysis again (run 3 of at most 6).\n"
				. 'progress',
			$result['stderr'],
		);
		self::assertSame([$caller, $caller, $caller], $this->argumentVectors());
		$this->assertPrivateReportRemoved(3);
	}

	public function testAFindingNextToAStoreChangeIsPrintedOnlyFromTheSettledRun(): void
	{
		$this->scenario([
			['exitCode' => 1, 'stdout' => "stale finding\n", 'report' => self::CHANGED],
			['exitCode' => 1, 'stdout' => "finding\n"],
		]);

		$result = $this->converge(['analyse']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("finding\n", $result['stdout']);
	}

	public function testTheRunCapPrintsTheLastRunAndFails(): void
	{
		$this->scenario([['exitCode' => 0, 'stdout' => "last\n", 'report' => self::CHANGED]]);

		$result = $this->converge(['--max-runs=2', 'analyse']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("last\n", $result['stdout']);
		self::assertSame(
			self::CHANGED . "\nThe Latte narrowing store changed, running the analysis again (run 2 of at most 2).\n"
				. 'The Latte narrowing store still changed on run 2 of at most 2 (--max-runs): ' . self::CHANGED . "\n",
			$result['stderr'],
		);
		self::assertCount(2, $this->calls());
		$this->assertPrivateReportRemoved(2);
	}

	public function testTheRunCapKeepsANonZeroExitCode(): void
	{
		$this->scenario([['exitCode' => 3, 'stdout' => '', 'report' => self::CHANGED]]);

		self::assertSame(3, $this->converge(['--max-runs=1', 'analyse'])['exitCode']);
	}

	public function testACrashIsForwardedUnchanged(): void
	{
		$this->scenario([['exitCode' => 255, 'stdout' => 'Fatal error', 'stderr' => 'trace']]);

		$result = $this->converge(['analyse']);

		self::assertSame(255, $result['exitCode']);
		self::assertSame('Fatal error', $result['stdout']);
		self::assertSame('trace', $result['stderr']);
		self::assertCount(1, $this->calls());
		$this->assertPrivateReportRemoved(1);
	}

	public function testASignalStopsTheLoopEvenWhenTheWriterReported(): void
	{
		$this->scenario([['exitCode' => 130, 'stdout' => '', 'report' => self::CHANGED, 'pruneEvaluated' => true]]);

		self::assertSame(130, $this->converge(['analyse'])['exitCode']);
		self::assertCount(1, $this->calls());
		$this->assertPrivateReportRemoved(1);
	}

	public function testAGeneratedBaselineKeepsExitCodeZero(): void
	{
		$this->scenario([['exitCode' => 0, 'stdout' => "Baseline generated with 3 errors.\n"]]);

		$result = $this->converge(['analyse', '-b']);

		self::assertSame(0, $result['exitCode']);
		self::assertSame("Baseline generated with 3 errors.\n", $result['stdout']);
		self::assertSame([['analyse', '-b']], $this->argumentVectors());
	}

	public function testAnsiIsAppendedOnlyForATerminalWithoutAnExplicitFlag(): void
	{
		$this->scenario([['exitCode' => 0, 'stdout' => '']]);

		$this->converge(['analyse'], true);
		$this->converge(['analyse', '--no-ansi'], true);
		$this->converge(['analyse', '--ansi'], true);
		$this->converge(['analyse'], false);

		self::assertSame([
			['analyse', '--ansi'],
			['analyse', '--no-ansi'],
			['analyse', '--ansi'],
			['analyse'],
		], $this->argumentVectors());
	}

	public function testPruneClearsTheResultCacheAndPrunesOnEveryRun(): void
	{
		$this->scenario([
			['exitCode' => 1, 'stdout' => "1\n", 'report' => self::CHANGED, 'pruneEvaluated' => true],
			['exitCode' => 1, 'stdout' => "refused\n", 'pruneEvaluated' => true],
		]);

		$result = $this->converge(
			['--prune', 'analyse', '-c', 'phpstan.neon', '--memory-limit=2G', '-afile.php', '--level=8'],
		);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("refused\n", $result['stdout']);
		self::assertStringStartsWith("cleared\n", $result['stderr']);
		self::assertStringNotContainsString('Nothing was pruned', $result['stderr']);
		$calls = $this->calls();
		self::assertSame(
			['arguments' => ['clear-result-cache', '-c', 'phpstan.neon', '--memory-limit=2G', '-afile.php'], 'prune' => false],
			['arguments' => $calls[0]['arguments'], 'prune' => $calls[0]['prune']],
		);
		self::assertSame('1', $calls[1]['prune']);
		self::assertSame('1', $calls[2]['prune']);
		self::assertCount(3, $calls);
	}

	public function testPruneWithoutAnEvaluatingWriterSaysSo(): void
	{
		$this->scenario([['exitCode' => 0, 'stdout' => "ok\n"]]);

		$result = $this->converge(['--prune', 'analyse']);

		self::assertSame(0, $result['exitCode']);
		self::assertSame("cleared\nNothing was pruned (narrowing disabled or store missing).\n", $result['stderr']);
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
			$this->temp,
		);

		self::assertSame(0, $runner->run(['--phpstan=' . self::FAKE, 'analyse']));
		self::assertCount(1, $this->calls());
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
	 * @return Generator<string, array{list<string>}>
	 */
	public function provideUnwritableTemporaryDirectoryArguments(): Generator
	{
		yield 'analyse' => [['analyse']];
		yield 'prune' => [['--prune', 'analyse']];
	}

	/**
	 * @param list<string> $arguments
	 *
	 * @dataProvider provideUnwritableTemporaryDirectoryArguments
	 */
	public function testAnUnwritableTemporaryDirectoryFailsWithOneMessageAndNoAnalysis(array $arguments): void
	{
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			self::markTestSkipped('Root writes into a read-only directory.');
		}

		$this->scenario([['exitCode' => 0, 'stdout' => 'ok']]);
		chmod($this->temp, 0500);

		try {
			$result = $this->converge($arguments);
		} finally {
			chmod($this->temp, 0700);
		}

		self::assertSame(1, $result['exitCode']);
		self::assertSame('', $result['stdout']);
		self::assertSame("Cannot create a private directory in {$this->temp}.\n", $result['stderr']);
		self::assertSame([], $this->calls());
	}

	private function assertPrivateReportRemoved(int $runs): void
	{
		$state = Json::decode(FileSystem::read($this->scenario), Json::FORCE_ARRAY);
		$reports = [];
		foreach ($state['calls'] as $i => $call) {
			self::assertTrue($call['reportIsFresh'], 'the report path must not exist when the run starts');
			self::assertSame('700', $call['directoryMode'], 'the report lives in a private directory');
			$reports[] = $state['reports'][$i];
		}

		self::assertCount($runs, $reports);
		self::assertCount(1, array_unique($reports), 'one private directory per invocation');
		self::assertStringStartsWith($this->temp . '/orisai-latte-converge-', $reports[0]);
		self::assertSame([], glob($this->temp . '/*'), 'the private directory is removed');
	}

	/**
	 * @param list<string> $arguments
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function converge(array $arguments, bool $tty = false): array
	{
		$stdout = $this->memory();
		$stderr = $this->file();
		$exitCode = (new ConvergeRunner(PHP_BINARY, self::FAKE, $stdout, $stderr, $tty, $this->temp))->run($arguments);

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
	 */
	private function scenario(array $runs): void
	{
		FileSystem::write($this->scenario, Json::encode(['run' => 0, 'calls' => [], 'reports' => [], 'runs' => $runs]));
	}

	/**
	 * @return list<array{arguments: list<string>, prune: string|false, reportIsFresh: bool}>
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
