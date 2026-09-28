<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class DiscoveryStoreActionPresenter extends Presenter
{

	public function actionDetail(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/discoveryStoreAction.detail.latte');
	}

}
