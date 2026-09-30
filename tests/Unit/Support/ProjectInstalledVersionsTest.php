<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Support;

use Composer\InstalledVersions;
use Latte\Engine;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class ProjectInstalledVersionsTest extends BaseTestCase
{

	private const PHAR_INSTALL = [
		'root' => ['name' => 'phpstan/phpstan-src'],
		'versions' => [
			'phpstan/phpstan-src' => ['version' => '2.2.16.0', 'install_path' => 'phar:///phpstan.phar'],
			'nette/di' => ['version' => '3.1.10.0', 'install_path' => 'phar:///phpstan.phar/vendor/nette/di'],
		],
	];

	private const PROJECT_INSTALL = [
		'root' => ['name' => 'acme/app'],
		'versions' => [
			'acme/app' => ['version' => 'dev-main', 'install_path' => '/app'],
			'orisai/phpstan-nette' => ['version' => '1.0.0.0', 'install_path' => '/app/vendor/orisai/phpstan-nette'],
			'phpstan/phpstan' => ['version' => '2.2.16.0', 'install_path' => '/app/vendor/phpstan/phpstan'],
			'nette/di' => ['version' => '3.2.7.0', 'pretty_version' => 'v3.2.7', 'install_path' => '/app/vendor/nette/di'],
		],
	];

	public function testSelectsTheInstallListingThisExtensionRegardlessOfOrder(): void
	{
		foreach ([[self::PHAR_INSTALL, self::PROJECT_INSTALL], [self::PROJECT_INSTALL, self::PHAR_INSTALL]] as $rawData) {
			$versions = ProjectInstalledVersions::fromRawData($rawData);

			self::assertSame('3.2.7.0', $versions->getVersion('nette/di'));
			self::assertSame('v3.2.7', $versions->getPrettyVersion('nette/di'));
			self::assertNull($versions->getPrettyVersion('phpstan/phpstan'));
			self::assertSame('/app/vendor/nette/di', $versions->getInstallPath('nette/di'));
			self::assertSame('2.2.16.0', $versions->getVersion('phpstan/phpstan'));
			self::assertNull($versions->getVersion('phpstan/phpstan-src'));
			self::assertNull($versions->getVersion('nette/utils'));
		}
	}

	public function testNoInstallListingThisExtensionKnowsNothing(): void
	{
		$versions = ProjectInstalledVersions::fromRawData([self::PHAR_INSTALL]);

		self::assertNull($versions->getVersion('nette/di'));
		self::assertNull($versions->getPrettyVersion('nette/di'));
		self::assertNull($versions->getInstallPath('nette/di'));
	}

	public function testRealInstallAgreesWithComposerOnThisExtensionsOwnEntry(): void
	{
		self::assertSame(
			InstalledVersions::getInstallPath(ProjectInstalledVersions::PACKAGE),
			ProjectInstalledVersions::get()->getInstallPath(ProjectInstalledVersions::PACKAGE),
		);
	}

	// The install root of a package and everything below it belong to the package; the root
	// package's own files are first-party.
	public function testPackageContainingResolvesInstalledPackagesOnly(): void
	{
		$versions = ProjectInstalledVersions::get();
		$engineFile = (string) (new ReflectionClass(Engine::class))->getFileName();
		$latteRoot = (string) $versions->getInstallPath('latte/latte');

		self::assertSame('latte/latte', $versions->packageContaining($engineFile));
		self::assertSame('latte/latte', $versions->packageContaining($latteRoot));
		self::assertNull($versions->packageContaining(__FILE__));
		self::assertNull($versions->packageContaining(__DIR__ . '/does-not-exist.php'));
	}

}
