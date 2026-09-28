<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

// Inherits renderShared() and nothing else: the candidate path is resolved with late-static-binding
// semantics, so it lands under THIS class's presenter name, never the declaring parent's.
final class DiscoveryInheritedChildPresenter extends DiscoveryInheritedBasePresenter
{

}
