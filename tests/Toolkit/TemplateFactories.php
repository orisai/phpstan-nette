<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\Http\IRequest;
use Nette\Security\User;
use ReflectionClass;

final class TemplateFactories
{

	// nette/application 3.3 dropped the cacheStorage constructor parameter that sat before templateClass.
	public static function create(
		LatteFactory $latteFactory,
		?IRequest $httpRequest = null,
		?User $user = null,
		?string $templateClass = null
	): TemplateFactory
	{
		$args = InstalledVersionsGuard::satisfies('nette/application', '>=3.3')
			? [$latteFactory, $httpRequest, $user, $templateClass]
			: [$latteFactory, $httpRequest, $user, null, $templateClass];

		return (new ReflectionClass(TemplateFactory::class))->newInstanceArgs($args);
	}

}
