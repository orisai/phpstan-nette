<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Presenter;

final class FleetDiscovery04Presenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline4 = 'discovery-04';
	}

	public function renderExtra(): void
	{
	}

}
