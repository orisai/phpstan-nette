<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Reflection;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\ProjectRelativePath;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Reflection\LatteTemplateSourceLocator;
use PHPStan\BetterReflection\Identifier\Identifier;
use PHPStan\BetterReflection\Identifier\IdentifierType;
use PHPStan\BetterReflection\Reflector\Reflector;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedSingleFileSourceLocatorFactory;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedSingleFileSourceLocatorRepository;
use ReflectionProperty;
use RuntimeException;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function sys_get_temp_dir;
use function uniqid;

final class LatteTemplateSourceLocatorTest extends BaseTestCase
{

	public function testDisabledReturnsNullWithoutTouchingRepositoryOrFilesystem(): void
	{
		$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
		$factory->expects(self::never())->method('create');

		$locator = new LatteTemplateSourceLocator(
			new OptimizedSingleFileSourceLocatorRepository($factory),
			new LatteUniverse(['/nonexistent/latte-source-locator-test-path'], '/project'),
			'/nonexistent/latte-slice-store',
			false,
		);

		$identifier = new Identifier(
			'LatteTpl_templates_foo_latte_deadbeef',
			new IdentifierType(IdentifierType::IDENTIFIER_CLASS),
		);

		self::assertNull($locator->locateIdentifier($this->createMock(Reflector::class), $identifier));
		self::assertNull($this->classNameToFile($locator));
	}

	public function testNonClassIdentifierReturnsNullWithoutTouchingRepository(): void
	{
		$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
		$factory->expects(self::never())->method('create');

		$locator = new LatteTemplateSourceLocator(
			new OptimizedSingleFileSourceLocatorRepository($factory),
			new LatteUniverse(['/nonexistent/latte-source-locator-test-path'], '/project'),
			'/nonexistent/latte-slice-store',
			true,
		);

		$identifier = new Identifier(
			'LatteTpl_templates_foo_latte_deadbeef',
			new IdentifierType(IdentifierType::IDENTIFIER_FUNCTION),
		);

		self::assertNull($locator->locateIdentifier($this->createMock(Reflector::class), $identifier));
		self::assertNull($this->classNameToFile($locator));
	}

	public function testNonTemplateClassReturnsNullWithoutFilesystemEnumeration(): void
	{
		$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
		$factory->expects(self::never())->method('create');

		$locator = new LatteTemplateSourceLocator(
			new OptimizedSingleFileSourceLocatorRepository($factory),
			new LatteUniverse([__DIR__ . '/../Fixtures'], '/project'),
			'/nonexistent/latte-slice-store',
			true,
		);

		$identifier = new Identifier('SomeOtherClass', new IdentifierType(IdentifierType::IDENTIFIER_CLASS));

		self::assertNull($locator->locateIdentifier($this->createMock(Reflector::class), $identifier));
		self::assertNull($this->classNameToFile($locator));
	}

	public function testKnownTemplateClassDelegatesToRepositoryWithTheResolvedFile(): void
	{
		$fixture = $this->createTempLatteFixture();

		try {
			$relativePath = ProjectRelativePath::relativize($fixture['projectRoot'], $fixture['latteFile']);
			$className = TemplateClassName::forPath($relativePath);

			$signal = new RuntimeException('LatteTemplateSourceLocatorTest delegation probe');
			$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
			$factory->expects(self::once())
				->method('create')
				->with($fixture['latteFile'])
				->willThrowException($signal);

			$locator = new LatteTemplateSourceLocator(
				new OptimizedSingleFileSourceLocatorRepository($factory),
				new LatteUniverse([$fixture['templatesDir']], $fixture['projectRoot']),
				'/nonexistent/latte-slice-store',
				true,
			);

			$identifier = new Identifier($className, new IdentifierType(IdentifierType::IDENTIFIER_CLASS));

			$this->expectExceptionObject($signal);
			$locator->locateIdentifier($this->createMock(Reflector::class), $identifier);
		} finally {
			FileSystem::delete($fixture['projectRoot']);
		}
	}

	public function testUnknownTemplateClassNameReturnsNullWithoutTouchingRepository(): void
	{
		$fixture = $this->createTempLatteFixture();

		try {
			$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
			$factory->expects(self::never())->method('create');

			$locator = new LatteTemplateSourceLocator(
				new OptimizedSingleFileSourceLocatorRepository($factory),
				new LatteUniverse([$fixture['templatesDir']], $fixture['projectRoot']),
				'/nonexistent/latte-slice-store',
				true,
			);

			$identifier = new Identifier('LatteTpl_deadbeef', new IdentifierType(IdentifierType::IDENTIFIER_CLASS));

			self::assertNull($locator->locateIdentifier($this->createMock(Reflector::class), $identifier));
		} finally {
			FileSystem::delete($fixture['projectRoot']);
		}
	}

	public function testVanishedPathEntryIsSkippedCleanly(): void
	{
		$fixture = $this->createTempLatteFixture();

		try {
			$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
			$factory->expects(self::never())->method('create');

			$locator = new LatteTemplateSourceLocator(
				new OptimizedSingleFileSourceLocatorRepository($factory),
				new LatteUniverse(
					[$fixture['projectRoot'] . '/does-not-exist', $fixture['templatesDir']],
					$fixture['projectRoot'],
				),
				'/nonexistent/latte-slice-store',
				true,
			);

			$identifier = new Identifier('LatteTpl_deadbeef', new IdentifierType(IdentifierType::IDENTIFIER_CLASS));

			self::assertNull($locator->locateIdentifier($this->createMock(Reflector::class), $identifier));
		} finally {
			FileSystem::delete($fixture['projectRoot']);
		}
	}

	public function testKnownSliceClassDelegatesToRepositoryWithTheResolvedFile(): void
	{
		$fixture = $this->createTempLatteFixture();

		try {
			$sliceStoreDir = $fixture['projectRoot'] . '/Latte.sitescope';
			SiteScopeStore::bootstrap($sliceStoreDir, ['templates/foo.latte']);
			$className = SliceClassName::forPath('templates/foo.latte');
			$sliceFile = $sliceStoreDir . '/' . $className . '.php';

			$signal = new RuntimeException('LatteTemplateSourceLocatorTest slice delegation probe');
			$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
			$factory->expects(self::once())
				->method('create')
				->with($sliceFile)
				->willThrowException($signal);

			$locator = new LatteTemplateSourceLocator(
				new OptimizedSingleFileSourceLocatorRepository($factory),
				new LatteUniverse([$fixture['templatesDir']], $fixture['projectRoot']),
				$sliceStoreDir,
				true,
			);

			$identifier = new Identifier($className, new IdentifierType(IdentifierType::IDENTIFIER_CLASS));

			$this->expectExceptionObject($signal);
			$locator->locateIdentifier($this->createMock(Reflector::class), $identifier);
		} finally {
			FileSystem::delete($fixture['projectRoot']);
		}
	}

	public function testUnknownSliceClassNameReturnsNullWithoutTouchingRepository(): void
	{
		$fixture = $this->createTempLatteFixture();

		try {
			$sliceStoreDir = $fixture['projectRoot'] . '/Latte.sitescope';
			SiteScopeStore::bootstrap($sliceStoreDir, ['templates/foo.latte']);

			$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
			$factory->expects(self::never())->method('create');

			$locator = new LatteTemplateSourceLocator(
				new OptimizedSingleFileSourceLocatorRepository($factory),
				new LatteUniverse([$fixture['templatesDir']], $fixture['projectRoot']),
				$sliceStoreDir,
				true,
			);

			$identifier = new Identifier('LatteSlice_deadbeef', new IdentifierType(IdentifierType::IDENTIFIER_CLASS));

			self::assertNull($locator->locateIdentifier($this->createMock(Reflector::class), $identifier));
		} finally {
			FileSystem::delete($fixture['projectRoot']);
		}
	}

	public function testAbsentSliceStoreDirYieldsNullWithoutCrashing(): void
	{
		$fixture = $this->createTempLatteFixture();

		try {
			$factory = $this->createMock(OptimizedSingleFileSourceLocatorFactory::class);
			$factory->expects(self::never())->method('create');

			$locator = new LatteTemplateSourceLocator(
				new OptimizedSingleFileSourceLocatorRepository($factory),
				new LatteUniverse([$fixture['templatesDir']], $fixture['projectRoot']),
				$fixture['projectRoot'] . '/does-not-exist-sitescope',
				true,
			);

			$identifier = new Identifier('LatteSlice_deadbeef', new IdentifierType(IdentifierType::IDENTIFIER_CLASS));

			self::assertNull($locator->locateIdentifier($this->createMock(Reflector::class), $identifier));
		} finally {
			FileSystem::delete($fixture['projectRoot']);
		}
	}

	/**
	 * @return array{projectRoot: string, templatesDir: string, latteFile: string}
	 */
	private function createTempLatteFixture(): array
	{
		$projectRoot = sys_get_temp_dir() . '/latte-source-locator-test-' . uniqid('', true);
		$templatesDir = $projectRoot . '/templates';
		$latteFile = $templatesDir . '/foo.latte';
		FileSystem::write($latteFile, "{* fixture *}\n");

		return [
			'projectRoot' => $projectRoot,
			'templatesDir' => $templatesDir,
			'latteFile' => $latteFile,
		];
	}

	/**
	 * @return array<string, string>|null
	 */
	private function classNameToFile(LatteTemplateSourceLocator $locator): ?array
	{
		$property = new ReflectionProperty(LatteTemplateSourceLocator::class, 'classNameToFile');
		$property->setAccessible(true);

		return $property->getValue($locator);
	}

}
