<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Presenter;

final class FleetDiscovery02Presenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline2 = 'discovery-02';
	}

	public function renderExtra(): void
	{
	}

}
