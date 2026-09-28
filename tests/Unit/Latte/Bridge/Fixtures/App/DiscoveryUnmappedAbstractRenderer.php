<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// Same abstract, non-reverse-mapping shape with one difference: it declares a render hook of its
// own, so its unresolved view axis is a real hole.
abstract class DiscoveryUnmappedAbstractRenderer extends Presenter
{

	public function renderDetail(): void
	{
	}

}
