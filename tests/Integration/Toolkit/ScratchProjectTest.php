<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Toolkit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function bin2hex;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function is_dir;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function rtrim;
use function stream_get_contents;
use function sys_get_temp_dir;
use function touch;
use function trim;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

final class ScratchProjectTest extends BaseTestCase
{

	private const RACE_WORKER = <<<'PHP'
[, $autoload, $base, $barrier, $iterations] = $argv;
require $autoload;
while (!file_exists($barrier)) {
	usleep(50);
}
for ($i = 0; $i < (int) $iterations; $i++) {
	$project = \Tests\OriPhpstan\Nette\Toolkit\ScratchProject::create('race', "$base/$i/nested/root");
	echo rtrim($project->path(''), '/'), "\n";
}
PHP;

	private string $base;

	protected function setUp(): void
	{
		parent::setUp();
		$this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
			. 'orisai-phpstan-nette-race-' . bin2hex(random_bytes(6));
	}

	protected function tearDown(): void
	{
		if (is_dir($this->base)) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST,
			);
			foreach ($iterator as $file) {
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}

			rmdir($this->base);
		}

		parent::tearDown();
	}

	// The shared root and the per-test leaf are created one level at a time: under paratest two
	// workers race for the root, and a recursive mkdir() losing that race reports failure with the
	// leaf still missing.
	public function testProjectsShareOneRootAndOwnDistinctDirectories(): void
	{
		$first = ScratchProject::create('scratch-project');
		$second = ScratchProject::create('scratch-project');

		try {
			$firstRoot = rtrim($first->path(''), DIRECTORY_SEPARATOR);
			$secondRoot = rtrim($second->path(''), DIRECTORY_SEPARATOR);

			self::assertNotSame($firstRoot, $secondRoot);
			self::assertSame(dirname($firstRoot), dirname($secondRoot));
			self::assertDirectoryExists($firstRoot);
			self::assertDirectoryExists($secondRoot);
		} finally {
			$first->cleanup();
			$second->cleanup();
		}

		self::assertDirectoryDoesNotExist(rtrim($first->path(''), DIRECTORY_SEPARATOR));
		self::assertDirectoryDoesNotExist(rtrim($second->path(''), DIRECTORY_SEPARATOR));
	}

	public function testAbsentRootIsCreatedWithTheLeaf(): void
	{
		$root = $this->base . '/missing/root';

		$project = ScratchProject::create('scratch-project', $root);
		$leaf = rtrim($project->path(''), DIRECTORY_SEPARATOR);

		self::assertSame($root, dirname($leaf));
		self::assertDirectoryExists($leaf);
	}

	public function testConcurrentProcessesRacingForAnAbsentRootAllGetTheirLeaf(): void
	{
		$iterations = 50;
		$barrier = $this->base . '/go';
		$command = [
			PHP_BINARY,
			'-r',
			self::RACE_WORKER,
			'--',
			dirname(__DIR__, 3) . '/tests/autoload.php',
			$this->base . '/race',
			$barrier,
			(string) $iterations,
		];

		$workers = [];
		for ($worker = 0; $worker < 4; $worker++) {
			$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			self::assertIsResource($process);
			$workers[] = [$process, $pipes];
		}

		mkdir($this->base);
		touch($barrier);

		foreach ($workers as [$process, $pipes]) {
			$stdout = (string) stream_get_contents($pipes[1]);
			$stderr = (string) stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);

			self::assertSame(0, proc_close($process), $stderr . $stdout);

			$leaves = explode("\n", trim($stdout));
			self::assertCount($iterations, $leaves);
			foreach ($leaves as $leaf) {
				self::assertDirectoryExists($leaf);
			}
		}
	}

	public function testWriteCreatesEveryMissingParent(): void
	{
		$project = ScratchProject::create('scratch-project');

		try {
			$project->write('a/b/c.txt', 'nested');

			self::assertSame('nested', file_get_contents($project->path('a/b/c.txt')));
		} finally {
			$project->cleanup();
		}
	}

}
