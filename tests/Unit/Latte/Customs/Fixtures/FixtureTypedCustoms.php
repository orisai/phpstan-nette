<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

final class FixtureTypedCustoms
{

	public static function myFilter(int $a): string
	{
		return (string) $a;
	}

	public static function myFunction(int $a): string
	{
		return (string) $a;
	}

}
