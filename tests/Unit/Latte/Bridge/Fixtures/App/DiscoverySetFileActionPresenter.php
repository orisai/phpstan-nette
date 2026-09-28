<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class DiscoverySetFileActionPresenter extends Presenter
{

	public function actionFoo(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/custom-foo.latte');
	}

	public function actionBar(): void
	{
	}

}
