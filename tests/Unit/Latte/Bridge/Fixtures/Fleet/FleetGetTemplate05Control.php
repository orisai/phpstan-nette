<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Latte\Engine;

final class FleetGetTemplate05Control
{

	public function getTemplate(): FleetTemplate05
	{
		return new FleetTemplate05(new Engine());
	}

	public function render(): void
	{
		$this->getTemplate()->render();
	}

	public function renderCaption(): void
	{
		$tpl = $this->getTemplate();
		$tpl->caption5 = 'origin-05';
		$tpl->render();
	}

}
