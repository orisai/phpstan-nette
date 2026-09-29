<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version;

use LogicException;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
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
use function dirname;

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

	public function testLatte3InstallHasNoAdapterYet(): void
	{
		$factory = $this->factory(['latte/latte' => '3.1.6.0', 'nette/forms' => '3.3.0.0']);

		self::assertSame('3.1/provider', $factory->family()->id());

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Latte 3 adapter not available yet');

		$factory->create(new AdapterCollaborators(new DeclarationScanner()));
	}

	public function testLatte3InstallHasNoEngineReaderYet(): void
	{
		$factory = $this->factory(['latte/latte' => '3.0.26.0']);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Latte 3 engine reader not available yet');

		$factory->createEngineReader();
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

	public function testAccessorResolvesOnceAndOnlyWhenAsked(): void
	{
		$factory = $this->factory(['latte/latte' => '3.1.6.0']);
		$accessor = new LatteVersionAdapterAccessor($factory, new AdapterCollaborators(new DeclarationScanner()));

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Latte 3 adapter not available yet');

		$accessor->get();
	}

	public function testHarvesterAsksForTheReaderOnlyWhenAnEngineIsHarvested(): void
	{
		$factory = $this->factory(['latte/latte' => '3.1.6.0']);
		$loader = dirname(__DIR__, 2) . '/Customs/Fixtures/engine-loader-gettext.php';

		$unconfigured = new CustomsHarvester(new EngineSource(null, null), $factory);
		$configured = new CustomsHarvester(new EngineSource(null, $loader), $factory);

		self::assertSame(HarvestedCustoms::empty()->getSaltHash(), $unconfigured->harvest()->getSaltHash());
		self::assertSame(HarvestedCustoms::empty()->getSaltHash(), $configured->harvest()->getSaltHash());
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
