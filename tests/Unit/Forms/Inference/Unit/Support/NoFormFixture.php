<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Unit\Support;

final class NoFormFixture
{

	public function run(int $a): int
	{
		$x = $a + 1;
		$y = $x * 2;

		return $x + $y;
	}

}
