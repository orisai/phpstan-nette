<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// Shape (a) of the inherited-dispatch pin: the ABSTRACT declarer of the render hook. It resolves
// candidates for its own name too, but nothing ever renders them.
abstract class DiscoveryInheritedBasePresenter extends Presenter
{

	public function renderShared(): void
	{
	}

}
