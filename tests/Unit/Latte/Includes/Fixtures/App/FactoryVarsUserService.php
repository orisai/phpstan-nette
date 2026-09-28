<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Security\User;

// An application's own Security\User subclass, which is what a real container wires and what the
// vendor DefaultTemplate's `@var Nette\Security\User $user` is therefore only the SUPERtype of.
final class FactoryVarsUserService extends User
{

	public function __construct()
	{
	}

	// A member the vendor supertype does not have: proof that the type reaching the template body
	// is the WIRED class, not the declared one.
	public function fixtureOnlyMember(): bool
	{
		return true;
	}

}
