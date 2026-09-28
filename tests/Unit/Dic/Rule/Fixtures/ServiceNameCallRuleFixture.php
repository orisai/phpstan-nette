<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule\Fixtures;

use Nette\DI\Container;

final class ServiceNameCallRuleFixture
{

	public function run(Container $container, string $dynamicName): void
	{
		$container->getService('foo'); // OK - everywhere
		$container->getService('alphaOnly'); // error: not in beta
		$container->getService('nonexistent'); // error: nowhere
		$container->getService($dynamicName); // error: dynamic
		$container->getService('fooAlias'); // OK - alias
		$container->getByName('betaOnly'); // error: not in alpha
		$container->createService('nonexistent'); // error: nowhere
		$container->getServiceType('nonexistent'); // error: nowhere
		$container->isCreated('nonexistent'); // error: nowhere
		$container->hasService('nonexistent'); // error: always false
		$container->hasService('alphaOnly'); // OK - probing is hasService's purpose
		$container->hasService($dynamicName); // error: dynamic
		$container->getService('imported'); // OK - imported services exist
		$container->getService('chainedAlias'); // OK - recursive alias resolution
		$container->hasService('chainedAlias'); // error: single-hop, always false
		$container->createService('chainedAlias'); // error: single-hop, nowhere
		$container->isCreated('chainedAlias'); // error: single-hop, nowhere
	}

}
