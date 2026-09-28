<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;
use stdClass;

// $flashes declared WITHOUT a default: the factory always writes a non-null value, but a template
// built outside the factory keeps the property's implicit null.
final class FactoryVarsFlashlessTemplateReplica extends Template
{

	/** @var array<stdClass> */
	public $flashes;

}
