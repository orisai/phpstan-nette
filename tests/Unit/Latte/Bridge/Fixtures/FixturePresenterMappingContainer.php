<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures;

use Nette\Application\PresenterFactory;
use Nette\DI\Container;

final class FixturePresenterMappingContainer extends Container
{

	/** @var array<string, string> */
	private array $mapping;

	/**
	 * @param array<string, string> $mapping
	 */
	public function __construct(array $mapping)
	{
		parent::__construct();
		$this->mapping = $mapping;
	}

	public function getByType(string $type, bool $throw = true): ?object
	{
		$factory = new PresenterFactory();
		$factory->setMapping($this->mapping);

		return $factory instanceof $type ? $factory : null;
	}

}
