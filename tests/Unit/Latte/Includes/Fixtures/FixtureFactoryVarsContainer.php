<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures;

use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\DI\Container;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixtureBridgeLatteFactory;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTemplateReplica;

// A compiled container whose TemplateFactory carries a configured default template class - the
// factory-default rung of the template-class resolution ladder, which is what a renderer naming no
// class of its own resolves through.
final class FixtureFactoryVarsContainer extends Container
{

	public function getByType(string $type, bool $throw = true): ?object
	{
		$factory = new TemplateFactory(
			new FixtureBridgeLatteFactory(),
			null,
			null,
			null,
			FactoryVarsTemplateReplica::class,
		);

		return $factory instanceof $type ? $factory : null;
	}

}
