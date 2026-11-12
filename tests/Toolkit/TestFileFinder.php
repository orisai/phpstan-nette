<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PHPStan\File\DirectoryWalker;
use PHPStan\File\FileExcluder;
use PHPStan\File\FileFinder;
use PHPStan\File\FileHelper;
use PHPStan\File\FileStatSignatures;

final class TestFileFinder
{

	public static function create(string $workingDirectory): FileFinder
	{
		$helper = new FileHelper($workingDirectory);

		return new FileFinder(
			new FileExcluder($helper, []),
			$helper,
			['php'],
			new DirectoryWalker(new FileStatSignatures()),
		);
	}

}
