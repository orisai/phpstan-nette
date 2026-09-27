<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component\Attachment;

use OriPhpstan\Nette\Component\Attachment\AttachmentState;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * The attachment lattice and its one join, which is the whole soundness surface of slice 1: a
 * definite claim may leave a join only when every reaching path made it.
 */
final class AttachmentStateTest extends BaseTestCase
{

	public function testAnUnrecordedReferenceIsUnknownAndNeitherAttachedNorDetached(): void
	{
		$state = AttachmentState::initial();

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('form'));
		self::assertFalse($state->isAttached('form'));
		self::assertFalse($state->isDetached('form'));
	}

	public function testTheTwoDefiniteAnswersReadBackAsAttachedAndDetached(): void
	{
		$state = AttachmentState::initial()
			->withAttachment('attached', Certainty::HAPPENS)
			->withAttachment('detached', Certainty::NEVER)
			->withAttachment('either', Certainty::MAYBE);

		self::assertTrue($state->isAttached('attached'));
		self::assertFalse($state->isDetached('attached'));

		self::assertTrue($state->isDetached('detached'));
		self::assertFalse($state->isAttached('detached'));

		self::assertSame(Certainty::MAYBE, $state->attachmentOf('either'));
		self::assertFalse($state->isAttached('either'));
		self::assertFalse($state->isDetached('either'));
	}

	public function testDegradingToUnknownForgetsADefiniteAnswer(): void
	{
		$state = AttachmentState::initial()
			->withAttachment('form', Certainty::HAPPENS)
			->withAttachment('form', Certainty::UNKNOWN);

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('form'));
	}

	/**
	 * The precedent this join is written against. CompositionState::joinMap() used to overwrite a
	 * carried certainty with a key-existence verdict, so a name one arm alone produced came out of
	 * the join looking like one every path produced - and isset() then narrowed to constant true on
	 * a container that was genuinely absent. The same shape is available here: an attach on one arm
	 * and nothing on the other must not leave the join saying Yes.
	 */
	public function testAnAttachOnOneArmAloneDoesNotSurviveTheJoin(): void
	{
		$pre = AttachmentState::initial()->withAttachment('control', Certainty::NEVER);
		$arm = $pre->withAttachment('control', Certainty::HAPPENS);

		$joined = AttachmentState::join([$arm, $pre]);

		self::assertSame(Certainty::MAYBE, $joined->attachmentOf('control'));
		self::assertFalse($joined->isAttached('control'));
		self::assertFalse($joined->isDetached('control'));
	}

	public function testAnAnswerEveryReachingPathAgreesOnSurvivesTheJoin(): void
	{
		$first = AttachmentState::initial()->withAttachment('control', Certainty::HAPPENS);
		$second = AttachmentState::initial()->withAttachment('control', Certainty::HAPPENS);

		self::assertSame(Certainty::HAPPENS, AttachmentState::join([$first, $second])->attachmentOf('control'));
	}

	/**
	 * A reference only one path ever heard of joins to UNKNOWN rather than to that path's answer:
	 * the other path's default is UNKNOWN and Certainty::join() makes it absorbing. This is the
	 * degradation that keeps a first mention inside a branch from escaping the branch.
	 */
	public function testAReferenceMissingFromAReachingPathJoinsToUnknown(): void
	{
		$arm = AttachmentState::initial()->withAttachment('fresh', Certainty::NEVER);

		$joined = AttachmentState::join([$arm, AttachmentState::initial()]);

		self::assertSame(Certainty::UNKNOWN, $joined->attachmentOf('fresh'));
	}

	public function testDisagreeingArmsJoinToMaybe(): void
	{
		$attached = AttachmentState::initial()->withAttachment('control', Certainty::HAPPENS);
		$detached = AttachmentState::initial()->withAttachment('control', Certainty::NEVER);

		self::assertSame(Certainty::MAYBE, AttachmentState::join([$attached, $detached])->attachmentOf('control'));
	}

	/**
	 * A join no path reaches sits in front of dead code; it claims nothing rather than carrying the
	 * pre-state's answers past a point no execution arrives at.
	 */
	public function testAJoinNoPathReachesClaimsNothing(): void
	{
		self::assertSame(Certainty::UNKNOWN, AttachmentState::join([])->attachmentOf('control'));
	}

	public function testJoinIsOrderIndependent(): void
	{
		$attached = AttachmentState::initial()->withAttachment('control', Certainty::HAPPENS);
		$maybe = AttachmentState::initial()->withAttachment('control', Certainty::MAYBE);

		self::assertSame(
			AttachmentState::join([$attached, $maybe])->attachmentOf('control'),
			AttachmentState::join([$maybe, $attached])->attachmentOf('control'),
		);
	}

	public function testASinglePathJoinsToItself(): void
	{
		$state = AttachmentState::initial()
			->withAttachment('attached', Certainty::HAPPENS)
			->withAttachment('detached', Certainty::NEVER);

		$joined = AttachmentState::join([$state]);

		self::assertSame(Certainty::HAPPENS, $joined->attachmentOf('attached'));
		self::assertSame(Certainty::NEVER, $joined->attachmentOf('detached'));
	}

}
