<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\Template;

// How newer nette/application versions spell the two control-side properties: NATIVELY TYPED with
// no default, so neither is ever isInitialized() until the factory writes it - and a factory that
// got no control never does.
class FactoryVarsTypedControlTemplateReplica extends Template
{

	public ?Control $control;

	public ?Presenter $presenter;

}
