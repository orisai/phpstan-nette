<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;

final class ControlSetFilePhaseFixture extends Control
{

	public function render(): void
	{
		$this->template->setFile(__DIR__ . '/control-phase.latte');
		$this->template->render();
	}

	public function handleRefresh(): void
	{
		$this->template->setFile(__DIR__ . '/control-refresh.latte');
	}

}
