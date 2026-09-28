<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// Abstract and deliberately not *Presenter-named, so the mapping does not reverse-map it: the real
// corpus's abstract base presenters. Nothing renders here - no own dispatch method, no own setFile -
// so there is nothing to discover and nothing to report.
abstract class DiscoveryUnmappedAbstractEndpoint extends Presenter
{

}
