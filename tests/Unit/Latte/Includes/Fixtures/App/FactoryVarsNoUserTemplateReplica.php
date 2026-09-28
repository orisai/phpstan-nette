<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;
use stdClass;

// The property_exists half of the vendor contract: a template class is free to declare only some of
// the factory's keys, and $user is deliberately absent here.
final class FactoryVarsNoUserTemplateReplica extends Template
{

	/** @var string */
	public $baseUrl;

	/** @var string */
	public $basePath;

	/** @var array<stdClass> */
	public $flashes = [];

}
