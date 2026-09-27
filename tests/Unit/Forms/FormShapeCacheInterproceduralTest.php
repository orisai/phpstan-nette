<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class FormShapeCacheInterproceduralTest extends BaseTestCase
{

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/form-shape-ip-test-' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		if (is_dir($this->dir)) {
			FileSystem::delete($this->dir);
		}
	}

	public function testMissReturnsNull(): void
	{
		$cache = new FormShapeCache($this->dir);
		self::assertNull($cache->lookupInterprocedural('Sample\\X#form'));
	}

	public function testRoundTripsByInterproceduralKey(): void
	{
		$cache = new FormShapeCache($this->dir);
		$cache->storeInterprocedural('Sample\\X#assignForm', FormShape::empty('Sample\\Forms\\AssignForm'), []);
		$got = $cache->lookupInterprocedural('Sample\\X#assignForm');
		self::assertInstanceOf(FormShape::class, $got);
		self::assertSame('Sample\\Forms\\AssignForm', $got->getClassName());
		self::assertNull($cache->lookupInterprocedural('Sample\\X#other'));
	}

	public function testClearDropsInterproceduralEntries(): void
	{
		$cache = new FormShapeCache($this->dir);
		$cache->storeInterprocedural('k#v', FormShape::empty('A'), []);
		$cache->clear();
		self::assertNull($cache->lookupInterprocedural('k#v'));
	}

}
