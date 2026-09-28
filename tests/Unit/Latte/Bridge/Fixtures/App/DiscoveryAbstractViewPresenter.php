<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// Same shape as DiscoveryVendorPresenter, abstract: a class Nette's own PresenterFactory refuses to
// instantiate, so its render hook never reaches the dispatch its formula candidates describe.
abstract class DiscoveryAbstractViewPresenter extends Presenter
{

	public function renderDetail(): void
	{
	}

}
