<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Metadata;

use Nette\DI\Container;
use OriPhpstan\Nette\Dic\Metadata\ContainerMetadata;
use OriPhpstan\Nette\Dic\Metadata\ContainerMetadataFactory;
use OriPhpstan\Nette\Dic\Metadata\ParameterTypeWidener;
use PHPStan\Type\ConstantTypeHelper;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ImportedService;
use function array_merge;
use function array_values;
use function get_class;

final class ContainerMetadataTest extends BaseTestCase
{

	/** @var array<string, Container> */
	private static array $containers;

	/** @var array<string, ContainerMetadata> */
	private static array $metadata;

	public static function setUpBeforeClass(): void
	{
		/** @var array<string, Container> $containers */
		$containers = require __DIR__ . '/../Fixtures/fixture-container-loader.php';
		self::$containers = $containers;

		$factory = new ContainerMetadataFactory();

		foreach (self::$containers as $profile => $container) {
			self::$metadata[$profile] = $factory->fromContainer($profile, $container);
		}
	}

	public function testExistenceMatchesRuntimeForAllNamesAndProbes(): void
	{
		$probes = ['foo', 'bar.baz', '06', 'fooAlias', 'alphaOnly', 'betaOnly', 'imported', 'nonexistent', 'Foo', 'FOO'];

		foreach (self::$metadata as $profile => $meta) {
			foreach (array_merge($meta->getServiceNames(), $probes) as $name) {
				self::assertSame(
					self::$containers[$profile]->hasService($name),
					$meta->hasService($name),
					"$profile: $name",
				);
			}
		}
	}

	public function testTypesMatchRuntimeForEveryService(): void
	{
		foreach (self::$metadata as $profile => $meta) {
			foreach ($meta->getServiceNames() as $name) {
				self::assertSame(
					self::$containers[$profile]->getServiceType($name),
					$meta->getServiceTypeName($name),
					"$profile: $name",
				);
			}
		}
	}

	public function testImportedServiceTypeComesFromTypesProperty(): void
	{
		self::assertSame(ImportedService::class, self::$metadata['alpha']->getServiceTypeName('imported'));
	}

	public function testFilePathPointsToCompiledContainer(): void
	{
		foreach (self::$metadata as $profile => $meta) {
			self::assertFileExists($meta->getFilePath());
			self::assertSame(get_class(self::$containers[$profile]), $meta->getClassName());
		}
	}

	public function testWideningAcceptsRealParameterValues(): void
	{
		$widener = new ParameterTypeWidener();

		foreach (self::$metadata as $profile => $meta) {
			$shape = $widener->widen($meta->getParameters());
			$actual = ConstantTypeHelper::getTypeFromValue(self::$containers[$profile]->getParameters());
			self::assertTrue($shape->accepts($actual, true)->yes(), $profile);
		}
	}

	public function testChainedAliasHopSemantics(): void
	{
		foreach (self::$metadata as $profile => $meta) {
			self::assertFalse($meta->hasService('chainedAlias'), $profile);
			self::assertTrue($meta->hasServiceRecursive('chainedAlias'), $profile);
			self::assertNull($meta->getServiceTypeName('chainedAlias'), $profile);
			self::assertSame(
				self::$containers[$profile]->getServiceType('chainedAlias'),
				$meta->getServiceTypeNameRecursive('chainedAlias'),
				$profile,
			);
		}
	}

	public function testCycleGuardTreatsCyclicAliasAsNonexistent(): void
	{
		$meta = new ContainerMetadata(
			'cyclic',
			'CyclicContainer',
			__FILE__,
			[],
			[],
			['a' => 'b', 'b' => 'a'],
			[],
			[],
			[],
		);

		self::assertFalse($meta->hasServiceRecursive('a'));
		self::assertNull($meta->getServiceTypeNameRecursive('a'));
	}

	public function testProfileMatchesLoaderKey(): void
	{
		foreach (self::$metadata as $profile => $meta) {
			self::assertSame($profile, $meta->getProfile());
		}
	}

	public function testAliasesMatchRuntime(): void
	{
		foreach (self::$metadata as $profile => $meta) {
			/** @var array<string, string> $aliases */
			$aliases = $this->readProtected(self::$containers[$profile], 'aliases');
			self::assertSame($aliases, $meta->getAliases());
		}
	}

	public function testTagsMatchRuntime(): void
	{
		foreach (self::$metadata as $profile => $meta) {
			/** @var array<string, array<string, mixed>> $tags */
			$tags = $this->readProtected(self::$containers[$profile], 'tags');
			self::assertSame($tags, $meta->getTags());
		}
	}

	public function testWiringMatchesRuntimeAndBucketHelper(): void
	{
		foreach (self::$metadata as $profile => $meta) {
			/** @var array<string, array<int, array<int, string>>> $wiring */
			$wiring = $this->readProtected(self::$containers[$profile], 'wiring');
			self::assertSame($wiring, $meta->getWiring());

			foreach ($wiring as $type => $buckets) {
				foreach ($buckets as $bucket => $names) {
					self::assertSame(array_values($names), $meta->getWiringBucket($type, $bucket));
				}
			}

			self::assertSame([], $meta->getWiringBucket('Nonexistent\Type', 0));
		}
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
