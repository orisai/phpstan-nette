<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;
use stdClass;

// The vendor DefaultTemplate's own declared surface, replicated: every property untyped with a @var
// docblock, and $flashes the only one carrying a default. The names are IMPORTED rather than spelled
// out, which this project's own coding standard requires - and PropertyTypeResolver hands back the
// raw @var string, so the short name is what reaches the template scope (see
// FactoryProvidedVarsTest::testShortDocblockNameIsKeptVerbatim for that limitation).
class FactoryVarsTemplateReplica extends Template
{

	/** @var FactoryVarsPresenterReplica */
	public $presenter;

	/** @var FactoryVarsControlReplica */
	public $control;

	/** @var FactoryVarsUserReplica */
	public $user;

	/** @var string */
	public $baseUrl;

	/** @var string */
	public $basePath;

	/** @var array<stdClass> */
	public $flashes = [];

}
