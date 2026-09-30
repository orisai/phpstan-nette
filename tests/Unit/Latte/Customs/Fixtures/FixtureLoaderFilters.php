<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

final class FixtureLoaderFilters
{

	public static function dyn(string $s): string
	{
		return $s;
	}

}
