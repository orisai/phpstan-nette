<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;

// $user typed so that the wired FactoryVarsUserService does not fit: 3.2 swallows the write's
// TypeError and leaves the property uninitialized, 3.1 throws.
final class FactoryVarsMistypedUserTemplateReplica extends Template
{

	public FactoryVarsUserReplica $user;

}
