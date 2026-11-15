<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Latte\Runtime\FilterInfo;

final class FilterHelpers
{

	public static function email(string $s): string
	{
		return $s;
	}

	public static function aware(FilterInfo $info, string $s): string
	{
		return $s;
	}

	public function format(string $s): string
	{
		return $s;
	}

	public function __invoke(int $i): string
	{
		return (string) $i;
	}

}
