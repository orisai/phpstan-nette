<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class CertaintyTest extends BaseTestCase
{

	public function testJoinIsIdempotent(): void
	{
		self::assertSame(Certainty::HAPPENS, Certainty::join(Certainty::HAPPENS, Certainty::HAPPENS));
		self::assertSame(Certainty::MAYBE, Certainty::join(Certainty::MAYBE, Certainty::MAYBE));
		self::assertSame(Certainty::NEVER, Certainty::join(Certainty::NEVER, Certainty::NEVER));
		self::assertSame(Certainty::UNKNOWN, Certainty::join(Certainty::UNKNOWN, Certainty::UNKNOWN));
	}

	public function testDisagreeingPathsJoinToMaybe(): void
	{
		self::assertSame(Certainty::MAYBE, Certainty::join(Certainty::HAPPENS, Certainty::NEVER));
		self::assertSame(Certainty::MAYBE, Certainty::join(Certainty::NEVER, Certainty::HAPPENS));
		self::assertSame(Certainty::MAYBE, Certainty::join(Certainty::HAPPENS, Certainty::MAYBE));
		self::assertSame(Certainty::MAYBE, Certainty::join(Certainty::MAYBE, Certainty::HAPPENS));
		self::assertSame(Certainty::MAYBE, Certainty::join(Certainty::NEVER, Certainty::MAYBE));
		self::assertSame(Certainty::MAYBE, Certainty::join(Certainty::MAYBE, Certainty::NEVER));
	}

	public function testUnknownDominates(): void
	{
		self::assertSame(Certainty::UNKNOWN, Certainty::join(Certainty::UNKNOWN, Certainty::HAPPENS));
		self::assertSame(Certainty::UNKNOWN, Certainty::join(Certainty::UNKNOWN, Certainty::MAYBE));
		self::assertSame(Certainty::UNKNOWN, Certainty::join(Certainty::UNKNOWN, Certainty::NEVER));
		self::assertSame(Certainty::UNKNOWN, Certainty::join(Certainty::HAPPENS, Certainty::UNKNOWN));
		self::assertSame(Certainty::UNKNOWN, Certainty::join(Certainty::NEVER, Certainty::UNKNOWN));
	}

}
