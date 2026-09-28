<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// Shape (b) of the inherited-dispatch pin: a NON-abstract declarer, itself a routable presenter.
// One declaring method, two independent candidate sets - this one's and its heir's.
class DiscoveryInheritedParentPresenter extends Presenter
{

	public function renderShared(): void
	{
	}

}
