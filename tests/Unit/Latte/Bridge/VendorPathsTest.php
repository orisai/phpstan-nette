<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use Nette\Application\UI\Control;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\VendorPaths;
use OriPhpstan\Nette\Latte\Includes\FirstPartyPaths;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function dirname;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;

final class VendorPathsTest extends BaseTestCase
{

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/orisai-vendor-paths-' . uniqid();
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->root);
		parent::tearDown();
	}

	public function testAFileUnderAnInstalledPackageIsVendor(): void
	{
		$vendorPaths = new VendorPaths(new FirstPartyPaths([dirname(__DIR__, 3)]));

		self::assertTrue($vendorPaths->contains($this->controlFile()));
		self::assertFalse($vendorPaths->contains(__FILE__));
	}

	public function testTheProjectRootAsFirstPartyKeepsAnInstalledPackageVendor(): void
	{
		$vendorPaths = new VendorPaths(new FirstPartyPaths([dirname(__DIR__, 4)]));

		self::assertTrue($vendorPaths->contains($this->controlFile()));
	}

	// A path-repository package is linked into the vendor directory: vendor only outside the
	// first-party paths, while a package installed into the vendor directory stays vendor.
	public function testALinkedPackageInsideFirstPartyPathsIsNeverVendor(): void
	{
		FileSystem::write($this->root . '/packages/linked/src/Linked.php', '<?php');
		FileSystem::write($this->root . '/vendor/acme/installed/src/Installed.php', '<?php');
		symlink($this->root . '/packages/linked', $this->root . '/vendor/acme/linked');
		$installations = [[
			'root' => ['name' => 'acme/project'],
			'versions' => [
				'acme/project' => ['install_path' => $this->root . '/vendor/composer/../../'],
				'acme/linked' => ['install_path' => $this->root . '/vendor/composer/../acme/linked'],
				'acme/installed' => ['install_path' => $this->root . '/vendor/composer/../acme/installed'],
			],
		]];
		FileSystem::createDir($this->root . '/vendor/composer');

		$monorepo = new VendorPaths(new FirstPartyPaths([$this->root . '/packages']), $installations);
		self::assertFalse($monorepo->contains($this->root . '/packages/linked/src/Linked.php'));
		self::assertTrue($monorepo->contains($this->root . '/vendor/acme/installed/src/Installed.php'));

		$project = new VendorPaths(new FirstPartyPaths([$this->root]), $installations);
		self::assertFalse($project->contains($this->root . '/packages/linked/src/Linked.php'));
		self::assertTrue($project->contains($this->root . '/vendor/acme/installed/src/Installed.php'));

		$elsewhere = new VendorPaths(new FirstPartyPaths([$this->root . '/app']), $installations);
		self::assertTrue($elsewhere->contains($this->root . '/packages/linked/src/Linked.php'));
	}

	private function controlFile(): string
	{
		$file = (new ReflectionClass(Control::class))->getFileName();
		self::assertIsString($file);

		return $file;
	}

}
