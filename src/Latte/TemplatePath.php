<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte;

use function substr_compare;

final class TemplatePath
{

	public const Extension = '.latte';

	private function __construct()
	{
	}

	public static function is(string $path): bool
	{
		return substr_compare($path, self::Extension, -6) === 0;
	}

}
