<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Metadata;

use LogicException;
use Nette\DI\Container;
use Nette\DI\MissingServiceException;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use OriPhpstan\Nette\Dic\Metadata\ReceiverResolution;
use OriPhpstan\Nette\Dic\Type\ContainerMissingServicesType;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Accessory\HasMethodType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaVariant;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\BetaVariant;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\CustomContainer;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\NotAutowiredService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;
use function array_keys;
use function sprintf;

final class MultiContainerRegistryTest extends PHPStanTestCase
{

	private const All = ['alpha', 'beta'];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::getContainer();
	}

	private static function registry(): MultiContainerRegistry
	{
		return new MultiContainerRegistry(__DIR__ . '/../Fixtures/fixture-container-loader.php');
	}

	public function testInactiveWithoutLoader(): void
	{
		$registry = new MultiContainerRegistry(null);
		self::assertFalse($registry->isActive());
	}

	public function testMissingLoaderFileThrows(): void
	{
		$registry = new MultiContainerRegistry('/nonexistent/loader.php');
		self::assertTrue($registry->isActive());
		$this->expectException(LogicException::class);
		$registry->getProfiles();
	}

	public function testLoaderReturningNonArrayThrows(): void
	{
		$registry = new MultiContainerRegistry(__DIR__ . '/Fixtures/bad-loader-non-array.php');
		$this->expectException(LogicException::class);
		$registry->getProfiles();
	}

	public function testLoaderReturningEmptyArrayThrows(): void
	{
		$registry = new MultiContainerRegistry(__DIR__ . '/Fixtures/bad-loader-empty.php');
		$this->expectException(LogicException::class);
		$registry->getProfiles();
	}

	public function testLoaderReturningInvalidEntryThrows(): void
	{
		$registry = new MultiContainerRegistry(__DIR__ . '/Fixtures/bad-loader-invalid-entry.php');
		$this->expectException(LogicException::class);
		$registry->getProfiles();
	}

	public function testLoaderReturningIntegerProfileKeyThrows(): void
	{
		$registry = new MultiContainerRegistry(__DIR__ . '/Fixtures/bad-loader-integer-key.php');
		$this->expectException(LogicException::class);
		$registry->getProfiles();
	}

	public function testServiceExistence(): void
	{
		self::assertSame(
			['alpha' => true, 'beta' => false],
			self::registry()->getServiceExistence('alphaOnly', false, self::All),
		);
		self::assertSame(
			['alpha' => false, 'beta' => false],
			self::registry()->getServiceExistence('nonexistent', false, self::All),
		);
	}

	public function testServiceTypeUnion(): void
	{
		$type = self::registry()->getServiceType('sharedName', false, self::All);
		self::assertNotNull($type);
		self::assertSame(AlphaVariant::class . '|' . BetaVariant::class, $type->describe(VerbosityLevel::precise()));
		self::assertNull(self::registry()->getServiceType('nonexistent', false, self::All));
	}

	public function testChainedAliasExistencePerHopMode(): void
	{
		self::assertSame(
			['alpha' => false, 'beta' => false],
			self::registry()->getServiceExistence('chainedAlias', false, self::All),
		);
		self::assertSame(
			['alpha' => true, 'beta' => true],
			self::registry()->getServiceExistence('chainedAlias', true, self::All),
		);
	}

	public function testChainedAliasTypePerHopMode(): void
	{
		self::assertNull(self::registry()->getServiceType('chainedAlias', false, self::All));

		$type = self::registry()->getServiceType('chainedAlias', true, self::All);
		self::assertNotNull($type);
		self::assertSame(FooService::class . '|' . FooServiceBeta::class, $type->describe(VerbosityLevel::precise()));

		self::assertSame([], self::registry()->getServiceTypeNames('chainedAlias', false, self::All));
		self::assertSame(
			['alpha' => FooService::class, 'beta' => FooServiceBeta::class],
			self::registry()->getServiceTypeNames('chainedAlias', true, self::All),
		);
	}

	public function testResolvedServiceMethodName(): void
	{
		$registry = self::registry();
		self::assertSame('createServiceFoo', $registry->getResolvedServiceMethodName('foo', false, self::All));
		self::assertSame('createServiceFoo', $registry->getResolvedServiceMethodName('fooRealAlias', false, self::All));
		self::assertSame(
			'createServiceFooRealAlias',
			$registry->getResolvedServiceMethodName('chainedAlias', false, self::All),
		);
		self::assertSame('createServiceFoo', $registry->getResolvedServiceMethodName('chainedAlias', true, self::All));
		self::assertSame('createServiceBar__baz', $registry->getResolvedServiceMethodName('bar.baz', false, self::All));
		self::assertSame(
			'createServiceNonexistent',
			$registry->getResolvedServiceMethodName('nonexistent', false, self::All),
		);
	}

	public function testTypeLookup(): void
	{
		$lookup = self::registry()->getTypeLookup(DuplicateService::class, self::All);
		self::assertSame(['dupA', 'dupB'], $lookup['alpha']->getAutowiredNames());
		$lookup = self::registry()->getTypeLookup(NotAutowiredService::class, self::All);
		self::assertTrue($lookup['alpha']->isKnown());
		self::assertSame([], $lookup['alpha']->getAutowiredNames());
		self::assertSame(['notAutowired'], $lookup['alpha']->getAllNames());
		$lookup = self::registry()->getTypeLookup('Tests\\Nowhere\\Unknown', self::All);
		self::assertFalse($lookup['alpha']->isKnown());
	}

	public function testTagQueries(): void
	{
		self::assertSame(['alpha' => true, 'beta' => false], self::registry()->getTagExistence('alpha.tag', self::All));
		self::assertNull(self::registry()->getTagValueType('no.such.tag', self::All));
		$valueType = self::registry()->getTagValueType('shared.tag', self::All);
		self::assertNotNull($valueType);
		self::assertSame('array{priority: int}', $valueType->describe(VerbosityLevel::precise()));
	}

	public function testGetServiceTypeNames(): void
	{
		self::assertSame(
			['alpha' => AlphaVariant::class, 'beta' => BetaVariant::class],
			self::registry()->getServiceTypeNames('sharedName', false, self::All),
		);
		self::assertSame(
			['alpha' => AlphaOnlyService::class],
			self::registry()->getServiceTypeNames('alphaOnly', false, self::All),
		);
	}

	public function testGetContainerFilePaths(): void
	{
		$paths = self::registry()->getContainerFilePaths();
		self::assertCount(2, $paths);

		foreach ($paths as $path) {
			self::assertFileExists($path);
		}
	}

	public function testGetContainerFilesByProfile(): void
	{
		$files = self::registry()->getContainerFilesByProfile();
		self::assertSame(['alpha', 'beta'], array_keys($files));

		foreach ($files as $path) {
			self::assertFileExists($path);
		}
	}

	public function testMergedParametersType(): void
	{
		$shape = self::registry()->getMergedParametersType(self::All);
		$described = $shape->describe(VerbosityLevel::precise());
		self::assertStringContainsString('alphaOnlyParam?: string', $described);
		self::assertStringContainsString('betaOnlyParam?: string', $described);
		self::assertStringContainsString('mixedTypeParam: int|string', $described);
		self::assertStringContainsString('intParam: int', $described);
		self::assertStringNotContainsString('alpha-secret-value', $described);
		self::assertStringContainsString('alphaOnly?: list<string>', $described);

		$emptyParamType = $shape->getOffsetValueType(new ConstantStringType('emptyParam'));
		self::assertSame('array', $emptyParamType->describe(VerbosityLevel::precise()));
	}

	public function testMergedParametersAcceptEveryProfile(): void
	{
		$shape = self::registry()->getMergedParametersType(self::All);
		foreach (require __DIR__ . '/../Fixtures/fixture-container-loader.php' as $profile => $container) {
			$actual = ConstantTypeHelper::getTypeFromValue($container->getParameters());
			self::assertTrue($shape->accepts($actual, true)->yes(), $profile);
		}
	}

	public function testResolveProfiles(): void
	{
		$registry = self::registry();

		self::assertResolution(true, self::All, $registry->resolveProfiles(new ObjectType(Container::class)));
		self::assertResolution(
			false,
			['alpha'],
			$registry->resolveProfiles(new ObjectType(FixtureContainerFactory::AlphaClassName)),
		);
		self::assertResolution(
			false,
			['beta'],
			$registry->resolveProfiles(new ObjectType(FixtureContainerFactory::BetaClassName)),
		);
		self::assertResolution(false, self::All, $registry->resolveProfiles(TypeCombinator::union(
			new ObjectType(FixtureContainerFactory::AlphaClassName),
			new ObjectType(FixtureContainerFactory::BetaClassName),
		)));
		self::assertResolution(true, self::All, $registry->resolveProfiles(TypeCombinator::union(
			new ObjectType(Container::class),
			new ObjectType(FixtureContainerFactory::AlphaClassName),
		)));
		self::assertNull($registry->resolveProfiles(TypeCombinator::union(
			new ObjectType(FixtureContainerFactory::AlphaClassName),
			new ObjectType(CustomContainer::class),
		)));
		self::assertNull($registry->resolveProfiles(new ObjectType(CustomContainer::class)));
		self::assertNull($registry->resolveProfiles(new ObjectType('Container_abcdef1234')));
		self::assertNull($registry->resolveProfiles(new ObjectType('stdClass')));
		self::assertNull($registry->resolveProfiles(new MixedType()));

		self::assertResolution(
			true,
			self::All,
			$registry->resolveProfiles(new ContainerMissingServicesType(['createServiceFoo'])),
		);
		self::assertResolution(true, self::All, $registry->resolveProfiles(TypeCombinator::intersect(
			new ObjectType(Container::class),
			new HasMethodType('createServiceFoo'),
		)));
		self::assertResolution(false, ['alpha'], $registry->resolveProfiles(TypeCombinator::intersect(
			new ObjectType(FixtureContainerFactory::AlphaClassName),
			new ContainerMissingServicesType(['createServiceBetaOnly']),
		)));
	}

	/**
	 * @param list<string> $expectedProfiles
	 */
	private static function assertResolution(
		bool $expectedBase,
		array $expectedProfiles,
		?ReceiverResolution $resolution
	): void
	{
		self::assertNotNull($resolution);
		self::assertSame($expectedBase, $resolution->isBase());
		self::assertSame($expectedProfiles, $resolution->getProfiles());
	}

	public function testSingleProfileSubsetQueries(): void
	{
		$registry = self::registry();

		self::assertSame(['alpha' => true], $registry->getServiceExistence('alphaOnly', false, ['alpha']));
		self::assertSame(['beta' => false], $registry->getServiceExistence('alphaOnly', false, ['beta']));

		$type = $registry->getServiceType('foo', true, ['alpha']);
		self::assertNotNull($type);
		self::assertSame(FooService::class, $type->describe(VerbosityLevel::precise()));

		self::assertSame(['alpha' => true], $registry->getTagExistence('alpha.tag', ['alpha']));
		self::assertSame(['beta' => false], $registry->getTagExistence('alpha.tag', ['beta']));

		$alphaShape = $registry->getMergedParametersType(['alpha'])->describe(VerbosityLevel::precise());
		self::assertStringContainsString('alphaOnlyParam: string', $alphaShape);
		self::assertStringNotContainsString('betaOnlyParam', $alphaShape);
		self::assertStringContainsString('mixedTypeParam: int', $alphaShape);
		self::assertStringNotContainsString('alpha-secret-value', $alphaShape);
	}

	public function testSingleProfileAnswersMatchRuntime(): void
	{
		$registry = self::registry();
		$probeNames = ['foo', 'alphaOnly', 'betaOnly', 'fooRealAlias', 'chainedAlias', 'sharedName', 'nonexistent'];

		foreach (require __DIR__ . '/../Fixtures/fixture-container-loader.php' as $profile => $container) {
			foreach ($probeNames as $name) {
				$existence = $registry->getServiceExistence($name, false, [$profile]);
				self::assertSame(
					[$profile => $container->hasService($name)],
					$existence,
					sprintf('%s existence of %s', $profile, $name),
				);

				$typeNames = $registry->getServiceTypeNames($name, true, [$profile]);

				try {
					$runtimeType = $container->getServiceType($name);
				} catch (MissingServiceException $e) {
					$runtimeType = null;
				}

				self::assertSame(
					$runtimeType === null || $runtimeType === '' ? [] : [$profile => $runtimeType],
					$typeNames,
					sprintf('%s type of %s', $profile, $name),
				);
			}
		}
	}

}
