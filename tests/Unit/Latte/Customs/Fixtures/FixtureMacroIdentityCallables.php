<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

final class FixtureMacroIdentityCallables
{

	public static function filter(string $s): string
	{
		return $s;
	}

	public static function fn(int $n): int
	{
		return $n;
	}

}
