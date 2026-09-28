<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;

final class FactoryVarsNarrowedTemplateReplica extends Template
{

	/** @var FactoryVarsNarrowedUserReplica */
	public $user;

}
