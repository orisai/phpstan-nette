<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic;

use Nette\DI\Container;
use Nette\DI\MissingServiceException;
use Nette\DI\ServiceCreationException;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaVariant;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\BetaVariant;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ImportedService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\InitializedProbe;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\NotAutowiredService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\SetupService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\Widget;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\WidgetFactory;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;
use function array_keys;
use function get_class;
use function method_exists;

final class ContainerRuntimeParityTest extends BaseTestCase
{

	/** @var array<string, Container> */
	private static array $containers;

	public static function setUpBeforeClass(): void
	{
		self::$containers = require __DIR__ . '/../../Unit/Dic/Fixtures/fixture-container-loader.php';
	}

	public function testProfilesLoaded(): void
	{
		self::assertSame(['alpha', 'beta'], array_keys(self::$containers));
		self::assertNotSame(get_class(self::$containers['alpha']), get_class(self::$containers['beta']));
		self::assertSame(FixtureContainerFactory::AlphaClassName, get_class(self::$containers['alpha']));
		self::assertSame(FixtureContainerFactory::BetaClassName, get_class(self::$containers['beta']));
	}

	public function testMethodNameMapping(): void
	{
		self::assertSame('createServiceFoo', Container::getMethodName('foo'));
		self::assertSame('createServiceBar__baz', Container::getMethodName('bar.baz'));
		self::assertSame('createService06', Container::getMethodName('06'));
		foreach (['foo', 'bar.baz', '06', 'sharedName', 'notAutowired', 'tagged', 'imported', 'widget.factory', 'foo.accessor', 'setupSvc', 'dupA', 'dupB'] as $name) {
			self::assertTrue(method_exists(self::$containers['alpha'], Container::getMethodName($name)), $name);
		}
	}

	public function testExistenceMatrix(): void
	{
		self::assertTrue(self::$containers['alpha']->hasService('alphaOnly'));
		self::assertFalse(self::$containers['beta']->hasService('alphaOnly'));
		self::assertTrue(self::$containers['beta']->hasService('betaOnly'));
		self::assertFalse(self::$containers['alpha']->hasService('betaOnly'));
		self::assertFalse(self::$containers['alpha']->hasService('nonexistent'));
		self::assertTrue(self::$containers['alpha']->hasService('fooAlias'));
		self::assertTrue(self::$containers['alpha']->hasService('imported'));
	}

	public function testGetServiceTypesAndWidestType(): void
	{
		self::assertSame(FooService::class, self::$containers['alpha']->getServiceType('foo'));
		self::assertSame(FooServiceBeta::class, self::$containers['beta']->getServiceType('foo'));
		self::assertSame(AlphaVariant::class, self::$containers['alpha']->getServiceType('sharedName'));
		self::assertSame(BetaVariant::class, self::$containers['beta']->getServiceType('sharedName'));
		self::assertSame(FooService::class, self::$containers['alpha']->getServiceType('fooAlias'));
		self::assertSame(ImportedService::class, self::$containers['alpha']->getServiceType('imported'));
		self::assertInstanceOf(FooService::class, self::$containers['alpha']->getService('foo'));
		$this->expectException(MissingServiceException::class);
		self::$containers['alpha']->getServiceType('nonexistent');
	}

	public function testImportedServiceCreateThrows(): void
	{
		$this->expectException(ServiceCreationException::class);
		self::$containers['alpha']->createService('imported');
	}

	public function testImportedServiceAddServiceSatisfies(): void
	{
		$container = self::$containers['alpha'];
		$container->addService('imported', new ImportedService());
		self::assertInstanceOf(ImportedService::class, $container->getService('imported'));
		$container->removeService('imported');
	}

	public function testGetByTypeSemantics(): void
	{
		self::assertInstanceOf(AlphaOnlyService::class, self::$containers['alpha']->getByType(AlphaOnlyService::class));
		self::assertNull(self::$containers['beta']->getByType(AlphaOnlyService::class, false));

		try {
			self::$containers['beta']->getByType(AlphaOnlyService::class);
			self::fail('expected MissingServiceException');
		} catch (MissingServiceException $e) {
		}

		// Not autowired: getByType throws even though the service exists.
		try {
			self::$containers['alpha']->getByType(NotAutowiredService::class);
			self::fail('expected MissingServiceException');
		} catch (MissingServiceException $e) {
		}

		// Ambiguous: two services of the same class -> throws even with $throw = false.
		try {
			self::$containers['alpha']->getByType(DuplicateService::class, false);
			self::fail('expected MissingServiceException (multiple)');
		} catch (MissingServiceException $e) {
			self::assertStringContainsString('Multiple', $e->getMessage());
		}
	}

	public function testWiringBucketsMatchRuntime(): void
	{
		$wiring = $this->readProtected(self::$containers['alpha'], 'wiring');
		self::assertCount(2, $wiring[DuplicateService::class][0] ?? []);
		$notAutowiredBuckets = $wiring[NotAutowiredService::class] ?? [];
		self::assertArrayNotHasKey(0, $notAutowiredBuckets);
		self::assertContains('notAutowired', $notAutowiredBuckets[2] ?? []);
		self::assertSame(['notAutowired'], self::$containers['alpha']->findByType(NotAutowiredService::class));
		self::assertSame(['dupA', 'dupB'], self::$containers['alpha']->findByType(DuplicateService::class));
		self::assertSame([], self::$containers['alpha']->findByType(BetaVariant::class));
	}

	public function testTags(): void
	{
		self::assertSame(['tagged' => ['priority' => 5]], self::$containers['alpha']->findByTag('shared.tag'));
		self::assertSame(['tagged' => true], self::$containers['alpha']->findByTag('alpha.tag'));
		self::assertSame([], self::$containers['beta']->findByTag('alpha.tag'));
	}

	public function testAliasResolution(): void
	{
		// 'key: @service' neon compiles to a forwarding service definition, not a Container::$aliases entry.
		$aliases = $this->readProtected(self::$containers['alpha'], 'aliases');
		self::assertArrayNotHasKey('fooAlias', $aliases);
		self::assertSame(FooService::class, self::$containers['alpha']->getServiceType('fooAlias'));
		self::assertSame(
			self::$containers['alpha']->getService('foo'),
			self::$containers['alpha']->getService('fooAlias'),
		);
	}

	public function testRealAliasViaCompilerExtension(): void
	{
		foreach (['alpha' => FooService::class, 'beta' => FooServiceBeta::class] as $profile => $fooType) {
			$container = self::$containers[$profile];
			$aliases = $this->readProtected($container, 'aliases');
			self::assertSame('foo', $aliases['fooRealAlias'], $profile);
			self::assertTrue($container->hasService('fooRealAlias'), $profile);
			self::assertSame($fooType, $container->getServiceType('fooRealAlias'), $profile);
			self::assertSame($container->getServiceType('foo'), $container->getServiceType('fooRealAlias'), $profile);
			self::assertSame($container->getService('foo'), $container->getService('fooRealAlias'), $profile);
		}
	}

	public function testChainedAliasRuntimeMatrix(): void
	{
		foreach (['alpha' => FooService::class, 'beta' => FooServiceBeta::class] as $profile => $fooType) {
			$container = self::$containers[$profile];
			$aliases = $this->readProtected($container, 'aliases');
			self::assertSame('fooRealAlias', $aliases['chainedAlias'], $profile);

			self::assertFalse($container->hasService('chainedAlias'), $profile);
			self::assertSame($container->getService('foo'), $container->getService('chainedAlias'), $profile);
			self::assertSame($fooType, $container->getServiceType('chainedAlias'), $profile);

			try {
				$container->isCreated('chainedAlias');
				self::fail('expected MissingServiceException');
			} catch (MissingServiceException $e) {
			}

			try {
				$container->createService('chainedAlias');
				self::fail('expected MissingServiceException');
			} catch (MissingServiceException $e) {
			}
		}
	}

	public function testRealAliasIsCreatedMirrorsTarget(): void
	{
		$class = get_class(self::$containers['alpha']);
		$fresh = new $class([]);
		self::assertFalse($fresh->isCreated('foo'));
		self::assertFalse($fresh->isCreated('fooRealAlias'));
		$fresh->getService('foo');
		self::assertTrue($fresh->isCreated('foo'));
		self::assertTrue($fresh->isCreated('fooRealAlias'));
	}

	public function testHasServiceMonotoneUnderRemoveService(): void
	{
		foreach (['alpha', 'beta'] as $profile) {
			$class = get_class(self::$containers[$profile]);
			$fresh = new $class([]);
			self::assertTrue($fresh->hasService('foo'), $profile);
			$fresh->getService('foo');
			$fresh->removeService('foo');
			self::assertTrue($fresh->hasService('foo'), $profile);
		}
	}

	public function testGetByTypeUniqueEverywhereNeverNull(): void
	{
		foreach (['alpha', 'beta'] as $profile) {
			self::assertNotNull(self::$containers[$profile]->getByType(SetupService::class, false), $profile);
		}
	}

	public function testParameterMergeOrder(): void
	{
		$class = get_class(self::$containers['alpha']);
		$overridden = new $class(['intParam' => 999, 'runtimeOnly' => 'x']);
		self::assertSame(999, $overridden->getParameters()['intParam']);
		self::assertSame('x', $overridden->getParameters()['runtimeOnly']);
		self::assertSame('alpha-secret-value', $overridden->getParameters()['stringParam']);
	}

	public function testParameterValues(): void
	{
		$params = self::$containers['alpha']->getParameters();
		self::assertSame('only-in-alpha', $params['alphaOnlyParam']);
		self::assertArrayNotHasKey('betaOnlyParam', $params);
		self::assertSame(['shared' => 'hello', 'alphaOnly' => ['a', 'b']], $params['nested']);
	}

	public function testSetupCallsRanOnCreation(): void
	{
		$service = self::$containers['alpha']->getByType(SetupService::class);
		self::assertInstanceOf(FooService::class, $service->dependency);
	}

	public function testGeneratedFactoryAndAccessor(): void
	{
		$factory = self::$containers['alpha']->getService('widget.factory');
		self::assertInstanceOf(WidgetFactory::class, $factory);
		self::assertInstanceOf(Widget::class, $factory->create('w'));
		$accessor = self::$containers['alpha']->getService('foo.accessor');
		self::assertInstanceOf(FooService::class, $accessor->get());
	}

	public function testIsCreated(): void
	{
		$container = self::$containers['beta'];
		self::assertFalse($container->isCreated('betaOnly'));
		$container->getService('betaOnly');
		self::assertTrue($container->isCreated('betaOnly'));
		$this->expectException(MissingServiceException::class);
		$container->isCreated('nonexistent');
	}

	public function testInitializeNotCalledByLoader(): void
	{
		InitializedProbe::$initialized = false;
		$factory = new FixtureContainerFactory();
		$alpha = $factory->create('alpha');
		$factory->create('beta');
		self::assertFalse(InitializedProbe::$initialized);
		// Older nette/di declares initialize() only on the compiled subclass, not on Nette\DI\Container
		$alpha->initialize();
		self::assertTrue(InitializedProbe::$initialized);
	}

	/**
	 * @return mixed
	 */
	private function readProtected(Container $container, string $property)
	{
		$reflection = new ReflectionProperty(Container::class, $property);
		$reflection->setAccessible(true);

		return $reflection->getValue($container);
	}

}
