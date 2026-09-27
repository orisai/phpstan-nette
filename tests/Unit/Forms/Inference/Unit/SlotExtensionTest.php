<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Unit;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use PHPStan\Type\StringType;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class SlotExtensionTest extends BaseTestCase
{

	public function testRetainsControlMeta(): void
	{
		$slot = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			[],
			'Nette\\Forms\\Controls\\TextInput',
			false,
			'text',
		);
		self::assertSame('Nette\\Forms\\Controls\\TextInput', $slot->getControlClass());
		self::assertFalse($slot->isNullable());
		self::assertSame('text', $slot->getAcceptedSetSpec());
	}

	public function testDefaultsPreserveCallsites(): void
	{
		$slot = new ComponentSlot('a', new StringType(), Certainty::HAPPENS, []);
		self::assertNull($slot->getControlClass());
		self::assertFalse($slot->isNullable());
		self::assertNull($slot->getAcceptedSetSpec());
	}

	public function testMergeKeepsControlMetaWhenSameDropsWhenDivergent(): void
	{
		$a = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			['n1'],
			'Nette\\Forms\\Controls\\TextInput',
			false,
			'text',
		);
		$b = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::MAYBE,
			['n2'],
			'Nette\\Forms\\Controls\\TextInput',
			false,
			'text',
		);
		$m = $a->mergeDuplicate($b);
		self::assertSame('Nette\\Forms\\Controls\\TextInput', $m->getControlClass());
		self::assertSame('text', $m->getAcceptedSetSpec());

		$c = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			[],
			'Nette\\Forms\\Controls\\TextInput',
			false,
			'text',
		);
		$d = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			[],
			'Nette\\Forms\\Controls\\Checkbox',
			true,
			'checkbox',
		);
		$m2 = $c->mergeDuplicate($d);
		self::assertNull($m2->getControlClass());
		self::assertNull($m2->getAcceptedSetSpec());
		self::assertTrue($m2->isNullable());

		$e = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			[],
			'Nette\\Forms\\Controls\\TextInput',
			true,
			'text',
		);
		$f = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::MAYBE,
			[],
			'Nette\\Forms\\Controls\\TextInput',
			true,
			'text',
		);
		$m3 = $e->mergeDuplicate($f);
		self::assertTrue($m3->isNullable());
		self::assertSame('Nette\\Forms\\Controls\\TextInput', $m3->getControlClass());
		self::assertSame('text', $m3->getAcceptedSetSpec());
	}

	public function testMergeUnknownControlClassWinsOverKnown(): void
	{
		$known = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			[],
			'Nette\\Forms\\Controls\\TextInput',
			false,
			'text',
		);
		$unknown = new ComponentSlot('a', new StringType(), Certainty::HAPPENS, []);

		$m = $known->mergeDuplicate($unknown);
		self::assertNull($m->getControlClasses());
		self::assertNull($m->getControlClass());
	}

	public function testMergeDivergentOmittedYieldsMaybeOmissionKeepingPresence(): void
	{
		$omitted = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			[],
			null,
			false,
			null,
			false,
			false,
			Certainty::HAPPENS,
		);
		$notOmitted = new ComponentSlot(
			'a',
			new StringType(),
			Certainty::HAPPENS,
			[],
			null,
			false,
			null,
			false,
			false,
			Certainty::NEVER,
		);

		$m = $omitted->mergeDuplicate($notOmitted);
		self::assertFalse($m->isOmitted());
		self::assertSame(Certainty::MAYBE, $m->getOmission());
		self::assertTrue($m->valueMaybeAbsent());
		// Presence stays definite: both arms attach the component, only its omission diverges.
		self::assertSame(Certainty::HAPPENS, $m->getPresence());
	}

}
