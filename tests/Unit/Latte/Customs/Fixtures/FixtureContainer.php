<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Nette\DI\Container;

final class FixtureContainer extends Container
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

		$factory = new FixtureLatteFactory();

		return $factory instanceof $type ? $factory : null;
	}

}
