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
use function uniqid;
use const PHP_BINARY;

final class ConvergeRunnerTest extends BaseTestCase
{

	private const FAKE = __DIR__ . '/Fixtures/fake-phpstan.php';

	private string $scenario;

	protected function setUp(): void
	{
		parent::setUp();
		$this->scenario = sys_get_temp_dir() . '/latte-converge-test-' . getmypid() . '-' . uniqid('', true) . '.json';
		putenv('FAKE_PHPSTAN_SCENARIO=' . $this->scenario);
	}

	protected function tearDown(): void
	{
		putenv('FAKE_PHPSTAN_SCENARIO');
		FileSystem::delete($this->scenario);
		parent::tearDown();
	}

	public function testRerunsWhileTheStoreChangesThenPrintsTheCallersFormat(): void
	{
		$this->responses([
			$this->json(1, [$this->storeChanged()]),
			$this->json(1, [$this->storeChanged()]),
			$this->json(0, []),
			['exitCode' => 0, 'stdout' => "[OK] No errors\n", 'stderr' => ''],
		]);

		$result = $this->converge(['analyse', '-c', 'phpstan.neon', '--error-format=table', 'src']);

		self::assertSame(0, $result['exitCode']);
		self::assertSame("[OK] No errors\n", $result['stdout']);
		self::assertSame(
			"The Latte narrowing store changed, running the analysis again (run 2 of at most 6).\n"
				. "The Latte narrowing store changed, running the analysis again (run 3 of at most 6).\n",
			$result['stderr'],
		);
		$json = ['analyse', '-c', 'phpstan.neon', 'src', '--error-format=json'];
		self::assertSame([
			['arguments' => $json],
			['arguments' => $json],
			['arguments' => $json],
			['arguments' => ['analyse', '-c', 'phpstan.neon', '--error-format=table', 'src']],
		], $this->calls());
	}

	public function testJsonFormatReusesTheFinalRunOutput(): void
	{
		$final = $this->json(0, []);
		$this->responses([$this->json(1, [$this->storeChanged()]), $final]);

		$result = $this->converge(['analyse', '--error-format', 'json', 'src']);

		self::assertSame(0, $result['exitCode']);
		self::assertSame($final['stdout'], $result['stdout']);
		self::assertCount(2, $this->calls());
		self::assertSame(['analyse', 'src', '--error-format=json'], $this->calls()[1]['arguments']);
	}

	public function testAFindingNextToAStoreChangeIsReportedOnceTheStoreSettles(): void
	{
		$this->responses([
			$this->json(1, [$this->storeChanged(), $this->finding()]),
			$this->json(1, [$this->finding()]),
			['exitCode' => 1, 'stdout' => "finding\n", 'stderr' => 'progress'],
		]);

		$result = $this->converge(['analyse', 'src']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("finding\n", $result['stdout']);
		self::assertCount(3, $this->calls());
		self::assertSame(['analyse', 'src'], $this->calls()[2]['arguments']);
	}

	public function testAFindingWithoutAStoreChangeIsNotRerun(): void
	{
		$this->responses([
			$this->json(1, [$this->finding()]),
			['exitCode' => 1, 'stdout' => "finding\n", 'stderr' => ''],
		]);

		$result = $this->converge(['analyse', 'src']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame("finding\n", $result['stdout']);
		self::assertSame('', $result['stderr']);
		self::assertCount(2, $this->calls());
	}

	public function testTheRunCapFailsWithTheLastStoreChange(): void
	{
		$this->responses([$this->json(1, [$this->storeChanged()])]);

		$result = $this->converge(['--max-runs=2', 'analyse', 'src']);

		self::assertSame(1, $result['exitCode']);
		self::assertSame('', $result['stdout']);
		self::assertSame(
			"The Latte narrowing store changed, running the analysis again (run 2 of at most 2).\n"
				. "The Latte narrowing store did not settle within 2 runs (--max-runs); the last run reported:\n"
				. "The Latte narrowing store changed for 1 including template: a.latte.\n",
			$result['stderr'],
		);
		self::assertCount(2, $this->calls());
	}

	public function testAnUndecodableRunIsPassedThroughWithItsExitCode(): void
	{
		$this->responses([['exitCode' => 255, 'stdout' => 'Fatal error', 'stderr' => 'trace']]);

		$result = $this->converge(['analyse', 'src']);

		self::assertSame(255, $result['exitCode']);
		self::assertSame('Fatal error', $result['stdout']);
		self::assertSame('trace', $result['stderr']);
		self::assertCount(1, $this->calls());
	}

	public function testPhpstanPathCanBeOverridden(): void
	{
		$this->responses([$this->json(0, []), ['exitCode' => 0, 'stdout' => 'ok', 'stderr' => '']]);

		$runner = new ConvergeRunner(PHP_BINARY, '/nonexistent/phpstan', $this->stream(), $this->stream());

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
	private function converge(array $arguments): array
	{
		$stdout = $this->stream();
		$stderr = $this->stream();
		$exitCode = (new ConvergeRunner(PHP_BINARY, self::FAKE, $stdout, $stderr))->run($arguments);

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
	private function stream()
	{
		$stream = fopen('php://memory', 'w+');
		self::assertNotFalse($stream);

		return $stream;
	}

	/**
	 * @param list<array{exitCode: int, stdout: string, stderr: string}> $responses
	 */
	private function responses(array $responses): void
	{
		FileSystem::write($this->scenario, Json::encode(['calls' => [], 'responses' => $responses]));
	}

	/**
	 * @return list<array{arguments: list<string>}>
	 */
	private function calls(): array
	{
		return Json::decode(FileSystem::read($this->scenario), Json::FORCE_ARRAY)['calls'];
	}

	/**
	 * @param list<array{message: string, identifier: string}> $messages
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function json(int $exitCode, array $messages): array
	{
		$files = [];
		foreach ($messages as $message) {
			$files['/project/file.php']['messages'][] = $message + ['line' => 1, 'ignorable' => false];
		}

		return [
			'exitCode' => $exitCode,
			'stdout' => Json::encode(
				['totals' => ['errors' => 0, 'file_errors' => 0], 'files' => $files, 'errors' => []],
			),
			'stderr' => '',
		];
	}

	/**
	 * @return array{message: string, identifier: string}
	 */
	private function storeChanged(): array
	{
		return [
			'message' => 'The Latte narrowing store changed for 1 including template: a.latte.',
			'identifier' => 'orisai.nette.latte.narrowingStoreChanged',
		];
	}

	/**
	 * @return array{message: string, identifier: string}
	 */
	private function finding(): array
	{
		return ['message' => 'Undefined variable: $x', 'identifier' => 'variable.undefined'];
	}

}
