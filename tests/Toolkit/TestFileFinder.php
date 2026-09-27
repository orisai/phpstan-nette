<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PHPStan\File\DirectoryWalker;
use PHPStan\File\FileExcluder;
use PHPStan\File\FileFinder;
use PHPStan\File\FileHelper;
use function class_exists;

final class TestFileFinder
{

	public static function create(string $workingDirectory): FileFinder
	{
		$helper = new FileHelper($workingDirectory);
		$arguments = [new FileExcluder($helper, []), $helper, ['php']];
		if (class_exists(DirectoryWalker::class)) {
			$arguments[] = new DirectoryWalker();
		}

		return new FileFinder(...$arguments);
	}

}
