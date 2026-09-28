<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class DiscoveryLocatorPresenter extends Presenter
{

	use FixturePresenterTemplateLocator;

	public function actionDefault(): void
	{
	}

}
