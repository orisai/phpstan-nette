<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Toolkit;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function dirname;
use function file_get_contents;
use function rtrim;
use const DIRECTORY_SEPARATOR;

final class ScratchProjectTest extends BaseTestCase
{

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
