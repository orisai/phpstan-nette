<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;

// A single-property replica whose docblock names its class by an unqualified short name.
final class FactoryVarsShortNameTemplateReplica extends Template
{

	/** @var FactoryVarsUserReplica */
	public $user;

}
