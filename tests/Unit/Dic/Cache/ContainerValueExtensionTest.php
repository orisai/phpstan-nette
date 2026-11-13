<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Cache;

use Nette\DI\Container;
use OriPhpstan\Nette\Dic\Cache\ContainerValueExtension;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ObjectType;
use Tests\OriPhpstan\Nette\Toolkit\RecordingDependencyTracker;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService;

final class ContainerValueExtensionTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const LoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader.php';

	private const AlphaOnlyLoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader-alpha-only.php';

	private const RenamedLoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader-renamed.php';

	private const All = ['alpha', 'beta'];

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/../../../../extension.neon'];
	}

	public function testEveryQueryTracksTheQuestionItAnswered(): void
	{
		$registry = new MultiContainerRegistry(self::LoaderFile);
		$tracker = new RecordingDependencyTracker();

		$registry->resolveProfiles(new ObjectType(Container::class), $tracker);
		$registry->getServiceExistence('sharedName', true, self::All, $tracker);
		$registry->getResolvedServiceMethodName('sharedName', false, self::All, $tracker);
		$registry->getServiceType('sharedName', false, self::All, $tracker);
		$registry->getServiceTypeNames('sharedName', true, self::All, $tracker);
		$registry->getTypeLookup(DuplicateService::class, self::All, $tracker);
		$registry->getTagExistence('alpha.tag', self::All, $tracker);
		$registry->getTagValueType('alpha.tag', self::All, $tracker);
		$registry->getMergedParametersType(self::All, $tracker);

		self::assertSame(
			[
				[ContainerValueExtension::class, ContainerValueExtension::key(
					'resolveProfilesFor',
					[[Container::class], true],
				)],
				[ContainerValueExtension::class, ContainerValueExtension::key(
					'getServiceExistence',
					['sharedName', true, self::All],
				)],
				[
					ContainerValueExtension::class,
					ContainerValueExtension::key('getResolvedServiceMethodName', ['sharedName', false, self::All]),
				],
				[ContainerValueExtension::class, ContainerValueExtension::key(
					'getServiceType',
					['sharedName', false, self::All],
				)],
				[ContainerValueExtension::class, ContainerValueExtension::key(
					'getServiceTypeNames',
					['sharedName', true, self::All],
				)],
				[ContainerValueExtension::class, ContainerValueExtension::key(
					'getTypeLookup',
					[DuplicateService::class, self::All],
				)],
				[ContainerValueExtension::class, ContainerValueExtension::key(
					'getTagExistence',
					['alpha.tag', self::All],
				)],
				[ContainerValueExtension::class, ContainerValueExtension::key(
					'getTagValueType',
					['alpha.tag', self::All],
				)],
				[ContainerValueExtension::class, ContainerValueExtension::key('getMergedParametersType', [self::All])],
			],
			$tracker->values,
		);
		self::assertSame([], $tracker->files, 'a container answer never depends on a whole file');
	}

	public function testTheValueIsTheAnswerSoOnlyAMovedAnswerReanalyses(): void
	{
		$extension = $this->extension(self::LoaderFile);
		$again = $this->extension(self::LoaderFile);

		$present = ContainerValueExtension::key('getServiceType', ['sharedName', false, self::All]);
		$absent = ContainerValueExtension::key('getServiceType', ['nonexistent', false, self::All]);

		self::assertSame($extension->getValue($present), $again->getValue($present), 'a reloaded container must agree');
		self::assertNotSame($extension->getValue($present), $extension->getValue($absent));
	}

	public function testAProfileSetChangeMovesTheReceiverResolution(): void
	{
		$key = ContainerValueExtension::key('resolveProfilesFor', [[Container::class], true]);

		self::assertNotSame(
			$this->extension(self::LoaderFile)->getValue($key),
			$this->extension(self::AlphaOnlyLoaderFile)->getValue($key),
		);
		self::assertNotSame(
			$this->extension(self::LoaderFile)->getValue($key),
			$this->extension(self::RenamedLoaderFile)->getValue($key),
		);
	}

	public function testAQuestionNamingAProfileThatIsGoneHasAnAnswerOfItsOwn(): void
	{
		$key = ContainerValueExtension::key('getServiceType', ['sharedName', false, self::All]);

		self::assertSame('unavailable:LogicException', $this->extension(self::AlphaOnlyLoaderFile)->getValue($key));
	}

	public function testAnInactiveRegistryAnswersAConstant(): void
	{
		$extension = new ContainerValueExtension(TestGuard::of(self::getContainer()), new MultiContainerRegistry(null));

		self::assertSame(
			'inactive',
			$extension->getValue(ContainerValueExtension::key('getMergedParametersType', [[]])),
		);
	}

	public function testKeysAreStoredAsTheyAre(): void
	{
		$extension = $this->extension(self::LoaderFile);
		$key = ContainerValueExtension::key('getTagExistence', ['alpha.tag', self::All]);

		self::assertSame($key, $extension->keyFromResultCache($extension->keyToResultCache($key)));
	}

	private function extension(string $loaderFile): ContainerValueExtension
	{
		return new ContainerValueExtension(
			TestGuard::withContainerLoader(self::getContainer(), $loaderFile),
			new MultiContainerRegistry($loaderFile),
		);
	}

}
