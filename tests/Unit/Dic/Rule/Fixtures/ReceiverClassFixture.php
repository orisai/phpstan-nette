<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule\Fixtures;

use Container_5011b2e17d;
use Container_6a5cfd235d;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\BetaOnlyService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\CustomContainer;

final class ReceiverClassFixture
{

	public function alphaReceiver(Container_5011b2e17d $container): void
	{
		$container->getService('alphaOnly'); // OK: registered in alpha
		$container->getService('betaOnly'); // error: not registered in alpha
		$container->getService('nonexistent'); // error: not registered in alpha
		$container->hasService('alphaOnly'); // error: always true in alpha
		$container->hasService('betaOnly'); // error: always false in alpha
		$container->getByType(BetaOnlyService::class); // error: type unknown in alpha
		$container->findByTag('alpha.tag'); // OK: tag exists in alpha
	}

	public function betaReceiver(Container_6a5cfd235d $container): void
	{
		$container->getService('betaOnly'); // OK: registered in beta
		$container->findByTag('alpha.tag'); // error: tag missing in beta
	}

	/**
	 * @param Container_5011b2e17d|Container_6a5cfd235d $container
	 */
	public function unionReceiver($container): void
	{
		$container->getService('foo'); // OK: registered in both
		$container->getService('alphaOnly'); // error: not registered in beta
		$container->hasService('foo'); // error: always true, native hasMethod is not a guard
		$container->hasService('alphaOnly'); // OK: partial existence in subset, legitimate probe
	}

	public function unknownReceiver(CustomContainer $container, string $dynamicName): void
	{
		$container->getService('nonexistent'); // OK: unknown container, registry says nothing
		$container->getService($dynamicName); // OK: unknown container, even dynamic names pass
		$container->hasService('nonexistent'); // OK: unknown container
		$container->getByType('Tests\Nowhere\Unknown'); // OK: unknown container
		$container->findByTag('no.such.tag'); // OK: unknown container
	}

	/**
	 * @param Container_5011b2e17d|CustomContainer $container
	 */
	public function mixedUnknownUnion($container): void
	{
		$container->getService('nonexistent'); // OK: any unknown union member bails entirely
	}

}
