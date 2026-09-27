<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class UnknownInfoTest extends BaseTestCase
{

	public function testEmptyHasNoUnknown(): void
	{
		$info = new UnknownInfo();
		self::assertFalse($info->hasUnknown());
		self::assertSame([], $info->getReasons());
	}

	public function testWithReasonIsImmutableAndDeduplicatedSorted(): void
	{
		$info = (new UnknownInfo())
			->withReason(UnknownReason::DYNAMIC_NAME)
			->withReason(UnknownReason::DYNAMIC_NAME)
			->withReason(UnknownReason::EXTENSION_METHOD);
		self::assertTrue($info->hasUnknown());
		self::assertSame([UnknownReason::DYNAMIC_NAME, UnknownReason::EXTENSION_METHOD], $info->getReasons());
	}

	public function testMergeUnionsReasons(): void
	{
		$a = (new UnknownInfo())->withReason(UnknownReason::EXTENSION_METHOD);
		$b = (new UnknownInfo())->withReason(UnknownReason::DYNAMIC_NAME);
		self::assertSame(
			[UnknownReason::DYNAMIC_NAME, UnknownReason::EXTENSION_METHOD],
			$a->merge($b)->getReasons(),
		);
	}

}
