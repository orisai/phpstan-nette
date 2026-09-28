<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use function md5;
use function preg_replace;
use function substr;

final class TemplateClassName
{

	private function __construct()
	{
	}

	public static function forPath(string $projectRelativePath): string
	{
		$sanitized = preg_replace('~[^A-Za-z0-9_]~', '_', $projectRelativePath);

		return 'LatteTpl_' . $sanitized . '_' . substr(md5($projectRelativePath), 0, 8);
	}

}
