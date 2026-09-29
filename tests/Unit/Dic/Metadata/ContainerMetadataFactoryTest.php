<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Metadata;

use Nette\DI\Container;
use OriPhpstan\Nette\Dic\Metadata\ContainerMetadataFactory;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ChildService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ImportedService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\NotAutowiredService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;
use function property_exists;

final class ContainerMetadataFactoryTest extends BaseTestCase
{

	public function testServiceTypesMatchRuntime(): void
	{
		$factory = new ContainerMetadataFactory();

		foreach (['alpha', 'beta'] as $profile) {
			$container = (new FixtureContainerFactory())->create($profile);
			$meta = $factory->fromContainer($profile, $container);

			foreach ($meta->getServiceNames() as $name) {
				self::assertSame(
					$container->getServiceType($name),
					$meta->getServiceTypeName($name),
					"$profile: $name",
				);
			}
		}
	}

	public function testTypesComeFromWiring(): void
	{
		$container = (new FixtureContainerFactory())->create('alpha');
		if (property_exists(Container::class, 'types')) {
			$types = new ReflectionProperty(Container::class, 'types');
			$types->setAccessible(true);
			$types->setValue($container, []);
		}

		$meta = (new ContainerMetadataFactory())->fromContainer('alpha', $container);

		self::assertSame(Container::class, $meta->getServiceTypeName('container'));
		self::assertSame(ImportedService::class, $meta->getServiceTypeName('imported'));
		self::assertSame(ChildService::class, $meta->getServiceTypeName('childService'));
		self::assertSame(NotAutowiredService::class, $meta->getServiceTypeName('notAutowired'));
		self::assertSame(FooService::class, $meta->getServiceTypeName('foo'));
	}

	public function testRuntimeAddedServicesAreNotIndexed(): void
	{
		$container = (new FixtureContainerFactory())->create('alpha');
		$container->addService('runtimeInstance', new FooService());
		$container->addService('runtimeFactory', static fn (): FooService => new FooService());

		$meta = (new ContainerMetadataFactory())->fromContainer('alpha', $container);

		self::assertTrue($container->hasService('runtimeInstance'));
		self::assertTrue($container->hasService('runtimeFactory'));
		self::assertFalse($meta->hasService('runtimeInstance'));
		self::assertFalse($meta->hasService('runtimeFactory'));
		self::assertNull($meta->getServiceTypeName('runtimeFactory'));
	}

	/**
	 * @group nette32
	 */
	public function testReadsContainerWithoutTypesIndex(): void
	{
		self::assertFalse(property_exists(Container::class, 'types'));

		$container = (new FixtureContainerFactory())->create('alpha');
		$meta = (new ContainerMetadataFactory())->fromContainer('alpha', $container);

		self::assertSame(Container::class, $meta->getServiceTypeName('container'));
		self::assertSame(ImportedService::class, $meta->getServiceTypeName('imported'));
		self::assertSame(ChildService::class, $meta->getServiceTypeName('childService'));
	}

}
