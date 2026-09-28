<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Presenter;

final class FleetDiscovery03Presenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline3 = 'discovery-03';
	}

	public function renderExtra(): void
	{
	}

}
