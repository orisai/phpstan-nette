<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Latte\Engine;

final class FleetGetTemplate01Control
{

	public function getTemplate(): FleetTemplate01
	{
		return new FleetTemplate01(new Engine());
	}

	public function render(): void
	{
		$this->getTemplate()->render();
	}

	public function renderCaption(): void
	{
		$tpl = $this->getTemplate();
		$tpl->caption1 = 'origin-01';
		$tpl->render();
	}

}
