<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use Composer\InstalledVersions;
use Nette\Application\UI\Control;
use OriPhpstan\Nette\Latte\Bridge\VendorPaths;
use OriPhpstan\Nette\Latte\Includes\FirstPartyPaths;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function dirname;

final class VendorPathsTest extends BaseTestCase
{

	public function testAFileUnderAnInstalledPackageIsVendor(): void
	{
		$vendorPaths = new VendorPaths(new FirstPartyPaths([dirname(__DIR__, 3)]));

		self::assertTrue($vendorPaths->contains($this->controlFile()));
		self::assertFalse($vendorPaths->contains(__FILE__));
	}

	public function testAFileInsideFirstPartyPathsIsNeverVendorEvenUnderAnInstallRoot(): void
	{
		$installPath = InstalledVersions::getInstallPath('nette/application');
		self::assertNotNull($installPath);

		$vendorPaths = new VendorPaths(new FirstPartyPaths([$installPath . '/src']));

		self::assertFalse($vendorPaths->contains($this->controlFile()));
	}

	private function controlFile(): string
	{
		$file = (new ReflectionClass(Control::class))->getFileName();
		self::assertIsString($file);

		return $file;
	}

}
