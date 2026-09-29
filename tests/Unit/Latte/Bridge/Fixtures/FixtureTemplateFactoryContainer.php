<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures;

use Nette\DI\Container;
use Tests\OriPhpstan\Nette\Toolkit\TemplateFactories;

final class FixtureTemplateFactoryContainer extends Container
{

	private bool $hasFactory;

	public function __construct(bool $hasFactory = true)
	{
		parent::__construct();
		$this->hasFactory = $hasFactory;
	}

	public function getByType(string $type, bool $throw = true): ?object
	{
		if (!$this->hasFactory) {
			return null;
		}

		$factory = TemplateFactories::create(
			new FixtureBridgeLatteFactory(),
			null,
			null,
			FixtureFactoryDefaultTemplate::class,
		);

		return $factory instanceof $type ? $factory : null;
	}

}
