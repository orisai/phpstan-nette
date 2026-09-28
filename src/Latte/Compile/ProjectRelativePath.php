<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use function str_replace;

final class ProjectRelativePath
{

	private function __construct()
	{
	}

	public static function relativize(string $projectRoot, string $file): string
	{
		return str_replace($projectRoot . '/', '', $file);
	}

}
