<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside;

use Nette\Application\UI\Presenter;

class BoundaryOutsideFixture extends Presenter
{

	public function outsideMethod(): void
	{
	}

}
