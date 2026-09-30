<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Metadata;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;
use function putenv;

final class FixtureContainerVendorTest extends BaseTestCase
{

	public function testCompiledFixtureContainersAreKeyedByTheVendorDirectory(): void
	{
		$installed = VendorDirectory::name();
		$className = FixtureContainerFactory::className('alpha');

		putenv('COMPOSER_VENDOR_DIR=vendor-fixture-container-probe');
		try {
			$otherClassName = FixtureContainerFactory::className('alpha');
		} finally {
			putenv('COMPOSER_VENDOR_DIR=' . $installed);
		}

		self::assertNotSame($className, $otherClassName);
		self::assertSame($className, FixtureContainerFactory::className('alpha'));
	}

}
