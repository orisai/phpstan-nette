<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Reflection;

use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\BetterReflection\BetterReflectionSourceLocatorFactory;
use PHPStan\Testing\TestCaseSourceLocatorFactory;

// Builds the locator betterReflectionSourceLocator! wraps. Under PHPStan's own test harness
// (TestCase.neon) the phar's locator is the test one, and overriding the service must not swap it
// for the production one, or every RuleTestCase with this extension loaded reflects differently.
final class InnerSourceLocatorFactory
{

	private Container $container;

	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	public function create(): SourceLocator
	{
		$testFactories = $this->container->findServiceNamesByType(TestCaseSourceLocatorFactory::class);
		if ($testFactories !== []) {
			$factory = $this->container->getService($testFactories[0]);
			if ($factory instanceof TestCaseSourceLocatorFactory) {
				return $factory->create();
			}
		}

		return $this->container->getByType(BetterReflectionSourceLocatorFactory::class)->create();
	}

}
