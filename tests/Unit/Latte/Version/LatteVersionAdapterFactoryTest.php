<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version;

use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\ExtensionSourceSalt;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Version\AdapterCollaborators;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2Adapter;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2EngineReader;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterAccessor;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterFactory;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function get_class;

final class LatteVersionAdapterFactoryTest extends BaseTestCase
{

	public function testLatte2InstallGetsTheLatte2AdapterAndReader(): void
	{
		$factory = $this->factory(['latte/latte' => '2.11.7.0', 'nette/forms' => '3.1.15.0']);

		$adapter = $factory->create(new AdapterCollaborators(new DeclarationScanner()));

		self::assertInstanceOf(Latte2Adapter::class, $adapter);
		self::assertSame('2/macros', $adapter->family()->id());
		self::assertSame('2/macros', $factory->family()->id());
		self::assertInstanceOf(Latte2EngineReader::class, $factory->createEngineReader());
	}

	// The Latte 3 adapter is constructed by reflection and holds no Latte object of its own, so the
	// selection itself can be pinned on any install; the adapter's behaviour is tested under Latte 3.
	public function testLatte3InstallGetsTheLatte3Adapter(): void
	{
		$factory = $this->factory(['latte/latte' => '3.1.6.0', 'nette/forms' => '3.3.0.0']);

		self::assertSame('3.1/provider', $factory->family()->id());

		$adapter = $factory->create(new AdapterCollaborators(new DeclarationScanner()));

		self::assertNotInstanceOf(Latte2Adapter::class, $adapter);
		self::assertSame('3.1/provider', $adapter->family()->id());
		self::assertSame($factory->family(), $adapter->family());
	}

	// Constructed by class name like the adapter; it reads a Latte 3 engine only when asked to.
	public function testLatte3InstallGetsTheLatte3EngineReader(): void
	{
		$factory = $this->factory(['latte/latte' => '3.0.26.0']);

		self::assertSame(
			'OriPhpstan\\Nette\\Latte\\Version\\Latte3\\Latte3EngineReader',
			get_class($factory->createEngineReader()),
		);
	}

	public function testUnknownInstallFallsBackToTheLoadedEngineVersion(): void
	{
		InstalledVersionsGuard::requireLatteMajor(2);

		self::assertInstanceOf(
			Latte2Adapter::class,
			$this->factory(null)->create(new AdapterCollaborators(new DeclarationScanner())),
		);
	}

	public function testRealInstallAgreesWithTheInstalledLatteMajor(): void
	{
		InstalledVersionsGuard::requireLatteMajor(2);

		self::assertInstanceOf(Latte2Adapter::class, TestAdapter::create());
		self::assertInstanceOf(Latte2EngineReader::class, TestAdapter::engineReader());
	}

	public function testAccessorResolvesTheLatte3AdapterOnceWhenAsked(): void
	{
		$factory = $this->factory(['latte/latte' => '3.0.26.0', 'nette/forms' => '3.2.9.0']);
		$accessor = new LatteVersionAdapterAccessor($factory, new AdapterCollaborators(new DeclarationScanner()));

		$adapter = $accessor->get();

		self::assertSame('3.0/item', $adapter->family()->id());
		self::assertSame($adapter, $accessor->get());
	}

	public function testHarvesterAsksForTheReaderOnlyWhenAnEngineIsHarvested(): void
	{
		$factory = $this->factory(['latte/latte' => '3.1.6.0']);

		$unconfigured = new CustomsHarvester(
			new EngineSource(null, null),
			$factory,
			new ExtensionSourceSalt(null, null, ProjectInstalledVersions::get()),
		);

		self::assertSame(HarvestedCustoms::empty()->getSaltHash(), $unconfigured->harvest()->getSaltHash());
	}

	public function testAccessorMemoizesTheAdapter(): void
	{
		InstalledVersionsGuard::requireLatteMajor(2);
		$accessor = TestAdapter::accessor();

		self::assertSame($accessor->get(), $accessor->get());
	}

	/**
	 * @param array<string, string>|null $versions
	 */
	private function factory(?array $versions): LatteVersionAdapterFactory
	{
		$installed = [];
		if ($versions !== null) {
			$installed[ProjectInstalledVersions::PACKAGE] = ['version' => '1.0.0.0'];
			foreach ($versions as $package => $version) {
				$installed[$package] = ['version' => $version];
			}
		}

		return new LatteVersionAdapterFactory(
			ProjectInstalledVersions::fromRawData([['root' => [], 'versions' => $installed]]),
		);
	}

}
