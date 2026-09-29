<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Toolkit;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * @group latte3
 */
final class VersionGroupProbe extends BaseTestCase
{

	public static int $runs = 0;

	/**
	 * @group nette32
	 */
	public function testProbe(): void
	{
		self::$runs++;
		self::assertSame(1, self::$runs);
	}

}
