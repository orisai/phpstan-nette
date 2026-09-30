<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Tools;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Tools\Corpus\UpstreamPackage;
use RuntimeException;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

final class UpstreamPackageTest extends BaseTestCase
{

	public function testTag(): void
	{
		self::assertSame('v3.1.6', UpstreamPackage::tag('v3.1.6'));
		self::assertSame('v3.0.26', UpstreamPackage::tag('3.0.26'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot derive a release tag from version "3.1.x-dev".');
		UpstreamPackage::tag('3.1.x-dev');
	}

	public function testTestDirectoriesFollowTheInstalledLatteMajor(): void
	{
		$checkout = sys_get_temp_dir() . '/corpus-upstream-test-' . getmypid() . '-' . uniqid();
		foreach (['Bridges.Latte', 'Bridges.Latte2', 'Bridges.Latte3', 'Bridges.DI', 'Forms.Latte3'] as $directory) {
			FileSystem::createDir($checkout . '/tests/' . $directory);
		}

		try {
			[$latte, $application, $forms] = UpstreamPackage::all();

			self::assertSame('https://github.com/nette/latte', $latte->repository);
			self::assertSame(['tests'], $latte->testDirectories($checkout, 3));
			self::assertSame(
				['tests/Bridges.Latte', 'tests/Bridges.Latte3'],
				$application->testDirectories($checkout, 3),
			);
			self::assertSame(
				['tests/Bridges.Latte', 'tests/Bridges.Latte2'],
				$application->testDirectories($checkout, 2),
			);
			self::assertSame([], $forms->testDirectories($checkout, 2));
			self::assertSame($checkout . '/forms-3.2.9', $forms->checkoutDirectory($checkout, 'v3.2.9'));
		} finally {
			FileSystem::delete($checkout);
		}
	}

}
