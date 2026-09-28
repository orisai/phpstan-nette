<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\HeirModule;

use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryInheritedParentPresenter;

// Lives in another module directory that owns a templates/ subdir, so the heir's candidates differ
// from the concrete parent's in BOTH the directory and the presenter segment.
final class DiscoveryInheritedHeirPresenter extends DiscoveryInheritedParentPresenter
{

}
