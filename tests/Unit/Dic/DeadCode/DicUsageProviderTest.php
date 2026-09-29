<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\DeadCode;

use OriPhpstan\Nette\Dic\DeadCode\ContainerUsageExtractor;
use OriPhpstan\Nette\Dic\DeadCode\DicUsageProvider;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ChildService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ParentService;
use function sort;

final class DicUsageProviderTest extends PHPStanTestCase
{

	use VersionGroupGate;

	/**
	 * The fixture container constructs ChildService and calls configureParent() on it, but BOTH members
	 * are declared by ParentService. Emitting against the constructed class is the whole fix: the
	 * ancestor hop is then resolved by the dead-code detector's aggregate stage out of collected class
	 * definitions, instead of being decided while analysing ParentService.php - a file that PHPStan
	 * never requeues when ChildService's `extends` clause changes.
	 */
	public function testUsagesAreEmittedAgainstTheConstructedClass(): void
	{
		self::assertSame(
			[
				ChildService::class . '::__construct',
				ChildService::class . '::configureParent',
			],
			$this->usagesFor(ChildService::class),
		);
	}

	public function testDeclaringAncestorEmitsNothingOfItsOwn(): void
	{
		self::assertSame([], $this->usagesFor(ParentService::class));
	}

	public function testClassTheContainerNeverConstructsEmitsNothing(): void
	{
		self::assertSame([], $this->usagesFor(FooService::class));
	}

	public function testInactiveRegistryEmitsNothing(): void
	{
		self::assertSame([], $this->usagesFor(ChildService::class, null));
	}

	/**
	 * @return list<string>
	 */
	private function usagesFor(
		string $className,
		?string $loaderFile = __DIR__ . '/../Fixtures/fixture-container-loader.php'
	): array
	{
		$provider = new DicUsageProvider(
			new MultiContainerRegistry($loaderFile),
			new ContainerUsageExtractor(),
		);

		// Built by hand so the provider's real entry point is exercised without a collector round-trip
		$node = new InClassNode( // @phpstan-ignore phpstanApi.constructor
			new Class_($className),
			self::createReflectionProvider()->getClass($className),
		);

		$described = [];

		foreach ($provider->getUsages($node, $this->createMock(Scope::class)) as $usage) {
			$described[] = $usage->getMemberRef()->toHumanString();
		}

		sort($described);

		return $described;
	}

}
