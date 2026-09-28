<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule\Fixtures;

use Nette\DI\Container;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\NotAutowiredService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\SetupService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\Widget;

final class TypeLookupCallRuleFixture
{

	public function run(Container $container, string $dynamicType): void
	{
		$container->getByType(SetupService::class); // OK
		$container->getByType(AlphaOnlyService::class); // error: not resolvable in beta
		$container->getByType(NotAutowiredService::class); // error: not autowired (alpha, beta)
		$container->getByType(DuplicateService::class); // error: ambiguous (alpha, beta)
		$container->getByType(DuplicateService::class, false); // error: ambiguous - throws despite false
		$container->getByType(AlphaOnlyService::class, false); // OK - null-safe lookup
		$container->getByType('Tests\Nowhere\Unknown'); // error: not found anywhere
		$container->getByType($dynamicType); // error: dynamic
		$container->findByType(DuplicateService::class); // OK
		$container->findByType('Tests\Nowhere\Unknown'); // error: always empty
		$container->findByType(AlphaOnlyService::class); // error: not in beta
		$container->findByType($dynamicType); // error: dynamic
		$container->createInstance($dynamicType); // error: dynamic
		$container->createInstance(Widget::class); // OK
	}

}
