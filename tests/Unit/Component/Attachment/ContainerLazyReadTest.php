<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component\Attachment;

use OriPhpstan\Nette\Component\Attachment\ContainerLazyRead;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * The exact answer that retires the every-path approximation: what `Container::getComponent($name)`
 * ATTACHES, as against what it returns.
 *
 * Both NEVER arms are independent proofs, and both are needed. The presence arm alone would answer
 * "attaches" for the commonest read there is — a name a closed shape does not hold — and following
 * that read is what deleted the reports the read itself is judged by. The factory arm alone would
 * answer "attaches" for a form that happens to declare a factory for a child it already holds.
 */
final class ContainerLazyReadTest extends BaseTestCase
{

	public function testOnlyGetComponentIsTheLazyRead(): void
	{
		self::assertTrue(ContainerLazyRead::isLazyRead('getComponent'));

		// offsetGet and offsetExists reach the same lazy path, but through getComponent rather than in
		// their own bodies, so no body-reading consumer ever asks about them.
		self::assertFalse(ContainerLazyRead::isLazyRead('offsetGet'));
		self::assertFalse(ContainerLazyRead::isLazyRead('offsetExists'));
		self::assertFalse(ContainerLazyRead::isLazyRead('addComponent'));
	}

	/**
	 * Nette's convention, including the clause that makes it partial: createComponent() requires
	 * ucfirst($name) !== $name, so a child whose name is already capitalised can have no factory at
	 * all — which turns every read of it into a proven non-attachment rather than an unanswered one.
	 */
	public function testTheFactoryNameFollowsNettesUcfirstConvention(): void
	{
		self::assertSame('createComponentSub', ContainerLazyRead::factoryMethodFor('sub'));
		self::assertSame('createComponentUserFilter', ContainerLazyRead::factoryMethodFor('userFilter'));
		self::assertNull(ContainerLazyRead::factoryMethodFor('Sub'));
		self::assertNull(ContainerLazyRead::factoryMethodFor('_x'));
	}

	public function testAnAlreadyAttachedChildIsNeverAttachedAgain(): void
	{
		self::assertSame(
			Certainty::NEVER,
			ContainerLazyRead::attachesChild('sub', Certainty::HAPPENS, true),
		);
	}

	public function testAReceiverWithNoFactoryAttachesNothingWhateverThePresence(): void
	{
		foreach ([Certainty::NEVER, Certainty::MAYBE, Certainty::UNKNOWN] as $presence) {
			self::assertSame(
				Certainty::NEVER,
				ContainerLazyRead::attachesChild('nope', $presence, false),
				$presence,
			);
		}
	}

	/**
	 * The one arm that says a read WRITES: the child is definitely absent and a factory exists, so
	 * createComponent() runs and either returns a component the read adds or throws.
	 */
	public function testADefinitelyAbsentChildWithAFactoryIsAttached(): void
	{
		self::assertSame(
			Certainty::HAPPENS,
			ContainerLazyRead::attachesChild('sub', Certainty::NEVER, true),
		);
	}

	public function testAnUnsurePresenceWithAFactoryStaysMaybe(): void
	{
		self::assertSame(
			Certainty::MAYBE,
			ContainerLazyRead::attachesChild('sub', Certainty::MAYBE, true),
		);
		self::assertSame(
			Certainty::MAYBE,
			ContainerLazyRead::attachesChild('sub', Certainty::UNKNOWN, true),
		);
	}

	/**
	 * A name the caller could not read answers UNKNOWN rather than NEVER, which is what keeps the
	 * consumer descending — and therefore degrading — instead of proving a read inert on no evidence.
	 */
	public function testAnUnreadableNameIsUnknown(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			ContainerLazyRead::attachesChild(null, Certainty::NEVER, false),
		);
	}

	/**
	 * The caller splits a '-'-joined name and asks about the segment the receiver can actually gain;
	 * a joined name arriving here is a broken contract, so it degrades rather than guessing which of
	 * the two halves the presence answer describes.
	 */
	public function testAJoinedNameIsNotAnswered(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			ContainerLazyRead::attachesChild('sub-inner', Certainty::NEVER, false),
		);
	}

	/**
	 * holdsChildAfterRead() is very nearly attachesChild()'s inverse, and the row that makes it so is
	 * this one: a FACTORY makes the check TRUE, because offsetExists() runs the lazy block and
	 * attaches what comes back. Reasoning "not attached, therefore isset is false" inverts the whole
	 * diagnostic, so every row is pinned rather than the interesting ones.
	 */
	public function testAHeldChildIsAlwaysThere(): void
	{
		self::assertSame(
			Certainty::HAPPENS,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::HAPPENS, false),
		);
		self::assertSame(
			Certainty::HAPPENS,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::HAPPENS, true),
		);
	}

	public function testAFactoryMakesTheCheckTrueWhateverThePresence(): void
	{
		self::assertSame(
			Certainty::HAPPENS,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::NEVER, true),
			'absent when the check starts, held once it has run',
		);
		self::assertSame(
			Certainty::HAPPENS,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::MAYBE, true),
			'held either way, so an unsure presence still answers definitely',
		);
		self::assertSame(
			Certainty::HAPPENS,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::UNKNOWN, true),
		);
	}

	/**
	 * The always-FALSE case, and the only one: neither held nor buildable.
	 */
	public function testNeitherHeldNorBuildableIsTheOnlyFalse(): void
	{
		self::assertSame(
			Certainty::NEVER,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::NEVER, false),
		);
	}

	public function testAnUnsurePresenceWithNoFactoryIsMaybe(): void
	{
		self::assertSame(
			Certainty::MAYBE,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::MAYBE, false),
		);
		self::assertSame(
			Certainty::MAYBE,
			ContainerLazyRead::holdsChildAfterRead('sub', Certainty::UNKNOWN, false),
		);
	}

	public function testTheCheckAnswersNothingForAnUnreadableOrJoinedName(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			ContainerLazyRead::holdsChildAfterRead(null, Certainty::HAPPENS, true),
		);
		self::assertSame(
			Certainty::UNKNOWN,
			ContainerLazyRead::holdsChildAfterRead('sub-inner', Certainty::NEVER, false),
		);
	}

}
