<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;

final class SurfaceFloorControl extends Control
{

	public function render(): void
	{
		$this->template->render(__DIR__ . '/default.latte');
	}

}
