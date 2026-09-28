<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule\Fixtures;

use Nette\DI\Container;

final class TagCallRuleFixture
{

	public function run(Container $container, string $dynamicTag): void
	{
		$container->findByTag('shared.tag'); // OK - present everywhere
		$container->findByTag('alpha.tag'); // error: not in beta
		$container->findByTag('no.such.tag'); // error: nowhere
		$container->findByTag($dynamicTag); // error: dynamic
	}

}
