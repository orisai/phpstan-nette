<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class ForeignScope
{

	public function noForm(): void
	{
		$unrelated = 1;
	}

}
