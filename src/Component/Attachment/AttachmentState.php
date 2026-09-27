<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component\Attachment;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use function array_keys;

/**
 * Whether a component reference is in some parent's `$components` at a program point, one answer
 * per reference, three-valued:
 *
 *     Yes    Certainty::HAPPENS   provably attached
 *     No     Certainty::NEVER     provably not attached - a fresh `new X()` nothing has added yet
 *     Maybe  Certainty::MAYBE     paths disagree, or the walk cannot see the whole story
 *
 * The vocabulary is `Certainty` itself rather than a private enum, so a consumer that already meets
 * a presence can meet an attachment with the same operator. Its fourth value, UNKNOWN, is the
 * DEFAULT here and the one an unrecorded reference answers with: nothing has been proven about a
 * name this state never saw, and Certainty::join() makes UNKNOWN absorbing, so an unproven edge
 * cannot be joined back into a definite claim by any later path. Read sides therefore ask
 * isAttached()/isDetached() rather than comparing to MAYBE - both UNKNOWN and MAYBE mean the same
 * thing to a read side, which is that nothing may be claimed, and only the two definite answers are
 * actionable.
 *
 * A reference is named by a plain string, which is a local variable's name (`this` included) at the
 * walk that produced the state. Nothing here resolves a name to an object: two names for one
 * component are two references, and the transitions degrade both when they see the aliasing rather
 * than pretending one of them still knows.
 */
final class AttachmentState
{

	/**
	 * Only definite entries are held. An UNKNOWN reference is absent instead of recorded, so a
	 * state carries no distinction between never-seen and seen-and-unproven - there is none to
	 * carry, and keeping the map to the definite answers is what stops it growing with every local
	 * a walked body touches.
	 *
	 * @var array<string, string>
	 */
	private array $attachments;

	/**
	 * @param array<string, string> $attachments
	 */
	private function __construct(array $attachments)
	{
		$this->attachments = $attachments;
	}

	public static function initial(): self
	{
		return new self([]);
	}

	/**
	 * The state after a join, over the states of the paths that REACH it - the non-terminated arms,
	 * plus the pre-state whenever the construct can be fallen through. Every join in this design
	 * goes through here: a branch join, a loop body folded back over its pre-state, and the fold
	 * across return points are the same operation over different reaching sets.
	 *
	 * Each name is met across EVERY reaching path, and a path that holds no entry for the name
	 * contributes its default UNKNOWN, which Certainty::join() then makes absorbing. That is the
	 * whole soundness argument, and it is deliberately not the shape CompositionState::joinMap()
	 * had before 7c2fced9c: that fold OVERWROTE a carried certainty with a key-existence verdict, so
	 * a name added on one arm alone reached the join looking exactly like one added before it and
	 * came out HAPPENS. Nothing here can promote, because a definite answer survives only when it
	 * is the answer on every path, and a name absent from any of them meets to UNKNOWN.
	 *
	 * An empty reaching set means the code after the join is unreachable; it answers with the
	 * initial state, which claims nothing.
	 *
	 * @param list<self> $reaching
	 */
	public static function join(array $reaching): self
	{
		if ($reaching === []) {
			return self::initial();
		}

		$names = [];
		foreach ($reaching as $state) {
			foreach (array_keys($state->attachments) as $name) {
				$names[$name] = true;
			}
		}

		$joined = [];
		foreach (array_keys($names) as $name) {
			// Seeded from the first path rather than from a neutral element: UNKNOWN is absorbing, so
			// there is no neutral element to seed with. Meeting that path with itself once is free,
			// Certainty::join() being idempotent.
			$met = $reaching[0]->attachmentOf($name);
			foreach ($reaching as $state) {
				$met = Certainty::join($met, $state->attachmentOf($name));
			}

			if ($met !== Certainty::UNKNOWN) {
				$joined[$name] = $met;
			}
		}

		return new self($joined);
	}

	public function attachmentOf(string $reference): string
	{
		return $this->attachments[$reference] ?? Certainty::UNKNOWN;
	}

	public function isAttached(string $reference): bool
	{
		return $this->attachmentOf($reference) === Certainty::HAPPENS;
	}

	public function isDetached(string $reference): bool
	{
		return $this->attachmentOf($reference) === Certainty::NEVER;
	}

	public function withAttachment(string $reference, string $attachment): self
	{
		if ($this->attachmentOf($reference) === $attachment) {
			return $this;
		}

		$attachments = $this->attachments;
		if (
			$attachment === Certainty::HAPPENS
			|| $attachment === Certainty::NEVER
			|| $attachment === Certainty::MAYBE
		) {
			$attachments[$reference] = $attachment;
		} else {
			unset($attachments[$reference]);
		}

		return new self($attachments);
	}

}
