<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;
use Nette\Security\User;

// $user declared with a NATIVE TYPE and no default - how newer nette/application versions spell the
// factory-written properties. Such a property is never isInitialized() until something writes it,
// so getParameters() does not export it at all: with the dependency unwired the variable is ABSENT,
// not null.
final class FactoryVarsTypedUserTemplateReplica extends Template
{

	public ?User $user;

}
