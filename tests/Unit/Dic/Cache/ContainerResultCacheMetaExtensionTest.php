<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Cache;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Dic\Cache\ContainerResultCacheMetaExtension;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function str_replace;
use function uniqid;

final class ContainerResultCacheMetaExtensionTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const LoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader.php';

	private const AlphaOnlyLoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader-alpha-only.php';

	private const RenamedLoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader-renamed.php';

	private const DuplicatedLoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader-duplicated.php';

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/../../../../extension.neon'];
	}

	public function testImplementsExtensionInterface(): void
	{
		$extension = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::LoaderFile),
		);
		self::assertInstanceOf(ResultCacheMetaExtension::class, $extension);
	}

	public function testKeyIsStable(): void
	{
		$extension = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::LoaderFile),
		);
		self::assertSame('orisai.nette.dic.containers', $extension->getKey());
	}

	public function testInactiveRegistryHasStableSentinelHash(): void
	{
		$extension = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(null),
		);
		self::assertSame('inactive', $extension->getHash());
	}

	public function testHashIsDeterministicAcrossInstances(): void
	{
		$first = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::LoaderFile),
		);
		$second = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::LoaderFile),
		);

		self::assertSame($first->getHash(), $first->getHash());
		self::assertSame($first->getHash(), $second->getHash());
	}

	public function testHashDependsOnAnalysedContainers(): void
	{
		$all = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::LoaderFile),
		);
		$alphaOnly = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::AlphaOnlyLoaderFile),
		);

		self::assertNotSame('inactive', $all->getHash());
		self::assertNotSame($all->getHash(), $alphaOnly->getHash());
	}

	/**
	 * The profile NAME is analysed state - every rule message prints it and the profile set selects
	 * the subset each type extension resolves against - so a digest over the container FILES alone
	 * leaves a renamed profile serving a fully restored cache full of messages naming a profile that
	 * no longer exists. Both loaders below reference byte-identical container files.
	 */
	public function testHashDependsOnProfileNames(): void
	{
		$all = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::LoaderFile),
		);
		$renamed = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::RenamedLoaderFile),
		);

		self::assertNotSame($all->getHash(), $renamed->getHash());
	}

	public function testHashDependsOnProfilesSharingOneContainerFile(): void
	{
		$all = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::LoaderFile),
		);
		$duplicated = new ContainerResultCacheMetaExtension(
			TestGuard::of(self::getContainer()),
			new MultiContainerRegistry(self::DuplicatedLoaderFile),
		);

		self::assertNotSame($all->getHash(), $duplicated->getHash());
	}

	/**
	 * The other rows all move the container file SET or the loader's profile keys, so a digest reduced
	 * to `profile:path` - one that never hashes a byte of container - survives every one of them. This
	 * one changes what a container SAYS at an unchanged path, which is the only shape that can tell a
	 * content hash from a path list, and it is the mechanism protecting the other twelve consumers.
	 */
	public function testHashDependsOnContainerFileContentAtAnUnchangedPath(): void
	{
		$directory = __DIR__ . '/../../../../var/tools/PHPUnit.DicSaltContent/' . uniqid('', true);

		try {
			$registry = new MultiContainerRegistry($this->writeScratchLoader($directory));
			$extension = new ContainerResultCacheMetaExtension(TestGuard::of(self::getContainer()), $registry);

			$paths = $registry->getContainerFilePaths();
			$before = $extension->getHash();

			FileSystem::write(
				$paths[0],
				FileSystem::read($paths[0]) . "\n// a byte the container did not carry before\n",
			);

			self::assertSame($paths, $registry->getContainerFilePaths());
			self::assertNotSame($before, $extension->getHash());
		} finally {
			FileSystem::delete($directory);
		}
	}

	private function writeScratchLoader(string $directory): string
	{
		FileSystem::write($directory . '/config.neon', "parameters:\n\tprobe: 1\n");

		$loaderFile = $directory . '/loader.php';

		// A nowdoc with a placeholder rather than an interpolating heredoc: the generated code contains
		// `new $className([])`, and an escaped `\$` inside a heredoc is tokenised as a namespace
		// separator by the coding standard.
		FileSystem::write($loaderFile, str_replace('{{ROOT}}', __DIR__ . '/../../../..', <<<'PHP'
<?php declare(strict_types = 1);

require_once '{{ROOT}}/tests/autoload.php';

$dir = __DIR__;

$loader = new Nette\DI\ContainerLoader($dir . '/cache', true);
$className = $loader->load(
	static function (Nette\DI\Compiler $compiler) use ($dir): void {
		$compiler->loadConfig($dir . '/config.neon');
	},
	['dic-salt-content'],
);

return ['alpha' => new $className([])];

PHP));

		return $loaderFile;
	}

}
