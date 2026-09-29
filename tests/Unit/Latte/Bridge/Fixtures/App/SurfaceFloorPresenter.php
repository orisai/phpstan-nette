<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class SurfaceFloorPresenter extends Presenter
{

	public function renderDefault(): void
	{
		$this->template->title = 'x';
	}

}
