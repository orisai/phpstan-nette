<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Converge;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Converge\StoreDigest;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

final class StoreDigestTest extends BaseTestCase
{

	public function testDigestsSlicesByNameAndContent(): void
	{
		$dir = sys_get_temp_dir() . '/latte-store-digest-test-' . getmypid() . '-' . uniqid('', true);

		try {
			self::assertSame(['exists' => false, 'slices' => []], StoreDigest::of($dir));

			FileSystem::createDir($dir);
			self::assertSame(['exists' => true, 'slices' => []], StoreDigest::of($dir));

			FileSystem::write($dir . '/LatteSlice_b.php', 'b');
			FileSystem::write($dir . '/LatteSlice_a.php', 'a');
			FileSystem::write($dir . '/README', 'ignored');
			FileSystem::write($dir . '/LatteSlice_c.php.123.tmp', 'ignored');

			self::assertSame(
				['exists' => true, 'slices' => ['LatteSlice_a.php' => sha1('a'), 'LatteSlice_b.php' => sha1('b')]],
				StoreDigest::of($dir),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

}
