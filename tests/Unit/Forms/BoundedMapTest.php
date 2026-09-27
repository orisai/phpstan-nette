<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Support\BoundedMap;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class BoundedMapTest extends BaseTestCase
{

	public function testEvictsLeastRecentlyUsedBeyondLimit(): void
	{
		$map = new BoundedMap(2);

		$map->set('a', 1);
		$map->set('b', 2);
		$map->set('c', 3);

		self::assertFalse($map->has('a'));
		self::assertTrue($map->has('b'));
		self::assertTrue($map->has('c'));
	}

	public function testGetRefreshesRecency(): void
	{
		$map = new BoundedMap(2);

		$map->set('a', 1);
		$map->set('b', 2);
		$map->get('a');
		$map->set('c', 3);

		self::assertTrue($map->has('a'));
		self::assertFalse($map->has('b'));
		self::assertTrue($map->has('c'));
	}

	public function testSetRefreshesRecency(): void
	{
		$map = new BoundedMap(2);

		$map->set('a', 1);
		$map->set('b', 2);
		$map->set('a', 10);
		$map->set('c', 3);

		self::assertTrue($map->has('a'));
		self::assertSame(10, $map->get('a'));
		self::assertFalse($map->has('b'));
		self::assertTrue($map->has('c'));
	}

	public function testNullValuesAreDistinguishableViaHas(): void
	{
		$map = new BoundedMap(2);

		$map->set('a', null);

		self::assertTrue($map->has('a'));
		self::assertNull($map->get('a'));
		self::assertFalse($map->has('missing'));
		self::assertNull($map->get('missing'));
	}

	public function testClearRemovesAllEntries(): void
	{
		$map = new BoundedMap(2);

		$map->set('a', 1);
		$map->set('b', 2);
		$map->clear();

		self::assertFalse($map->has('a'));
		self::assertFalse($map->has('b'));
	}

}
