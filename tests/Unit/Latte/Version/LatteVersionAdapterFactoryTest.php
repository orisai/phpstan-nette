<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version;

use LogicException;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2Adapter;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterFactory;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;

final class LatteVersionAdapterFactoryTest extends BaseTestCase
{

	public function testLatte2InstallGetsTheLatte2Adapter(): void
	{
		$adapter = $this->factory(['latte/latte' => '2.11.7.0', 'nette/forms' => '3.1.15.0'])->create();

		self::assertInstanceOf(Latte2Adapter::class, $adapter);
		self::assertSame('2/macros', $adapter->family()->id());
	}

	public function testLatte3InstallHasNoAdapterYet(): void
	{
		$factory = $this->factory(['latte/latte' => '3.1.6.0', 'nette/forms' => '3.3.0.0']);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Latte 3 adapter not available yet');

		$factory->create();
	}

	public function testUnknownInstallFallsBackToTheLoadedEngineVersion(): void
	{
		InstalledVersionsGuard::requireLatteMajor(2);

		$adapter = $this->factory(null)->create();

		self::assertInstanceOf(Latte2Adapter::class, $adapter);
	}

	public function testRealInstallAgreesWithTheInstalledLatteMajor(): void
	{
		InstalledVersionsGuard::requireLatteMajor(2);

		$adapter = (new LatteVersionAdapterFactory(
			ProjectInstalledVersions::get(),
			new LatteCompiler(),
			new DeclarationScanner(),
			new TemplateFactExtractor(),
			new FormMacroCollector(new LatteUniverse([], '')),
		))->create();

		self::assertInstanceOf(Latte2Adapter::class, $adapter);
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
			new LatteCompiler(),
			new DeclarationScanner(),
			new TemplateFactExtractor(),
			new FormMacroCollector(new LatteUniverse([], '')),
		);
	}

}
