<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Presenter;

final class FleetDiscovery05Presenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline5 = 'discovery-05';
	}

	public function renderExtra(): void
	{
	}

}
