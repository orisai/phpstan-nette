<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Latte\Engine;

final class FleetGetTemplate02Control
{

	public function getTemplate(): FleetTemplate02
	{
		return new FleetTemplate02(new Engine());
	}

	public function render(): void
	{
		$this->getTemplate()->render();
	}

	public function renderCaption(): void
	{
		$tpl = $this->getTemplate();
		$tpl->caption2 = 'origin-02';
		$tpl->render();
	}

}
