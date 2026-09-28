<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Cache;

use OriPhpstan\Nette\Latte\Cache\LatteReflectionCacheBypass;
use PHPStan\Cache\CacheStorage;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class LatteReflectionCacheBypassTest extends BaseTestCase
{

	public function testLoadForLatteKeyedEntryNeverReachesInnerStorageAndAlwaysMisses(): void
	{
		$inner = $this->createMock(CacheStorage::class);
		$inner->expects(self::never())->method('load');
		$bypass = new LatteReflectionCacheBypass($inner);

		self::assertNull($bypass->load('ftm-/app/templates/Foo.latte', 'v1'));
	}

	public function testSaveForLatteKeyedEntryNeverReachesInnerStorage(): void
	{
		$inner = $this->createMock(CacheStorage::class);
		$inner->expects(self::never())->method('save');
		$bypass = new LatteReflectionCacheBypass($inner);

		$bypass->save('osfsl-/app/templates/Foo.latte-class-LatteTpl_x', 'v1', ['data' => 1]);
	}

	public function testNonLatteKeyedEntryPassesThroughToInnerStorage(): void
	{
		$inner = $this->createMock(CacheStorage::class);
		$inner->expects(self::once())->method('save')->with('ftm-/app/Model/Foo.php', 'v1', ['ok' => true]);
		$inner->expects(self::once())->method('load')->with('ftm-/app/Model/Foo.php', 'v1')
			->willReturn(['ok' => true]);
		$bypass = new LatteReflectionCacheBypass($inner);

		$bypass->save('ftm-/app/Model/Foo.php', 'v1', ['ok' => true]);

		self::assertSame(['ok' => true], $bypass->load('ftm-/app/Model/Foo.php', 'v1'));
	}

	public function testSliceFileKeyedEntryPassesThroughToInnerStorage(): void
	{
		// LatteSlice_*.php files are real files SiteScopeStore writes - their own content hash
		// correctly invalidates the phar's cache, so only ".latte"-keyed entries bypass, never
		// the generated slice ".php" ones.
		$key = 'ftm-/tests/PHPStan/Latte.sitescope/LatteSlice_x.php';
		$inner = $this->createMock(CacheStorage::class);
		$inner->expects(self::once())->method('save')->with($key, 'v1', ['ok' => true]);
		$inner->expects(self::once())->method('load')->with($key, 'v1')->willReturn(['ok' => true]);
		$bypass = new LatteReflectionCacheBypass($inner);

		$bypass->save($key, 'v1', ['ok' => true]);

		self::assertSame(['ok' => true], $bypass->load($key, 'v1'));
	}

}
