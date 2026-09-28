<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Latte\Engine;

final class FleetGetTemplate03Control
{

	public function getTemplate(): FleetTemplate03
	{
		return new FleetTemplate03(new Engine());
	}

	public function render(): void
	{
		$this->getTemplate()->render();
	}

	public function renderCaption(): void
	{
		$tpl = $this->getTemplate();
		$tpl->caption3 = 'origin-03';
		$tpl->render();
	}

}
