<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Presenter;

final class FleetDiscovery01Presenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline1 = 'discovery-01';
	}

	public function renderExtra(): void
	{
	}

}
