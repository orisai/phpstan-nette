<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;

// A single-property replica dedicated to the raw-@var-string limitation: whatever the docblock
// spells is what the template scope sees, and an imported short name does not resolve back to its
// FQCN on the way through.
final class FactoryVarsShortNameTemplateReplica extends Template
{

	/** @var FactoryVarsUserReplica */
	public $user;

}
