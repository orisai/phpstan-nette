<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Latte\Engine;

final class FleetGetTemplate04Control
{

	public function getTemplate(): FleetTemplate04
	{
		return new FleetTemplate04(new Engine());
	}

	public function render(): void
	{
		$this->getTemplate()->render();
	}

	public function renderCaption(): void
	{
		$tpl = $this->getTemplate();
		$tpl->caption4 = 'origin-04';
		$tpl->render();
	}

}
