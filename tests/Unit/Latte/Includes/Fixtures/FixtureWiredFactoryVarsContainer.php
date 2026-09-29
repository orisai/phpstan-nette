<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures;

use Nette\DI\Container;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixtureBridgeLatteFactory;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsUserService;
use Tests\OriPhpstan\Nette\Toolkit\TemplateFactories;

// The wired counterpart of FixtureFactoryVarsContainer: the same compiled-container seam, but with
// both optional TemplateFactory dependencies actually supplied, which is what turns the vendor's
// `$value !== null` condition from "unknown" into "always true" for $user, $baseUrl and $basePath.
final class FixtureWiredFactoryVarsContainer extends Container
{

	public function getByType(string $type, bool $throw = true): ?object
	{
		$factory = TemplateFactories::create(
			new FixtureBridgeLatteFactory(),
			new Request(new UrlScript('http://example.com/')),
			new FactoryVarsUserService(),
			FactoryVarsTemplateReplica::class,
		);

		return $factory instanceof $type ? $factory : null;
	}

}
