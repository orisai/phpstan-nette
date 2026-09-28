<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures;

use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\DI\Container;

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

		$factory = new TemplateFactory(
			new FixtureBridgeLatteFactory(),
			null,
			null,
			null,
			FixtureFactoryDefaultTemplate::class,
		);

		return $factory instanceof $type ? $factory : null;
	}

}
