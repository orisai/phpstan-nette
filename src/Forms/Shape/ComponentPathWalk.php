<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

/**
 * The outcome of walking a component-name path through a FormShape - what ComponentPath::walk()
 * proved, in the vocabulary every consumer of that walk needs. The consumers answer three different
 * questions (a TYPE, a DEFINITE-existence trinary, an ABSENCE classification) and each maps these
 * outcomes to its own answer, so the walk itself commits to none of them: it reports what it found
 * and stops.
 *
 * Stopping is not an error - it is the honest end of a proof. Only OUTCOME_LEAF and
 * OUTCOME_REPLICATOR carry something resolvable; every other outcome means the path cannot be
 * followed with what the shape knows, and the consumer degrades.
 */
final class ComponentPathWalk
{

	/**
	 * Every intermediate segment was a container the walk descended through; getShape() holds the
	 * container the leaf lives in and getSegment() the leaf name.
	 */
	public const OUTCOME_LEAF = 'leaf';

	/**
	 * The walk reached a replicator. It stops there rather than descending, because what the REST of
	 * the path means is a decision only the replicator can make: Nette hands the remainder to the
	 * replicator's own getComponent(), where a decimal name is a dynamically created ROW and any
	 * other name is one of its OWN children (a control added straight onto addDynamic()'s return
	 * value, e.g. an addNode button). getShape() holds the shape the replicator lives in,
	 * getSegment() the name it is attached under, and getReplicatorHop() the replicator together
	 * with every segment after it.
	 */
	public const OUTCOME_REPLICATOR = 'replicator';

	/** An intermediate segment appears in no channel of getShape() at all. */
	public const OUTCOME_MISSING = 'missing';

	/**
	 * An intermediate segment IS a known child but not a traversable one - a slot, or a
	 * componentTypes-only entry. Nette throws "is not container and cannot have '…' component" for a
	 * component that really is not a container, but the shape carries no reflection to prove that a
	 * control class is not itself an IContainer, so this stops the walk rather than condemning it.
	 */
	public const OUTCOME_NOT_CONTAINER = 'notContainer';

	/**
	 * $requireDefinitePresence only: an intermediate container/replicator whose presence is not
	 * Certainty::HAPPENS. A hop through a component that may not be attached is not a proven path.
	 */
	public const OUTCOME_MAYBE_PRESENT = 'maybePresent';

	/**
	 * A segment Nette\ComponentModel\Container::addComponent() could never have registered - the
	 * empty string a trailing/leading/doubled separator produces, or anything else failing
	 * Container::NameRegexp. getComponent() throws for it no matter what the container holds, so
	 * unlike every other stopping outcome this one is a proven runtime failure rather than an
	 * unproven path. Only ever produced for a MULTI-segment path: with a single segment "invalid"
	 * and "absent" are the same lookup failure and the absence machinery already owns it.
	 */
	public const OUTCOME_INVALID_NAME = 'invalidName';

	/** @var self::OUTCOME_* */
	private string $outcome;

	private FormShape $shape;

	private string $segment;

	private ?ReplicatorShape $replicator;

	/** @var list<string> */
	private array $remainder;

	/**
	 * @param self::OUTCOME_* $outcome
	 * @param list<string> $remainder
	 */
	private function __construct(
		string $outcome,
		FormShape $shape,
		string $segment,
		?ReplicatorShape $replicator,
		array $remainder
	)
	{
		$this->outcome = $outcome;
		$this->shape = $shape;
		$this->segment = $segment;
		$this->replicator = $replicator;
		$this->remainder = $remainder;
	}

	public static function leaf(FormShape $shape, string $leaf): self
	{
		return new self(self::OUTCOME_LEAF, $shape, $leaf, null, []);
	}

	/**
	 * @param list<string> $remainder
	 */
	public static function replicator(
		FormShape $shape,
		string $replicatorName,
		ReplicatorShape $replicator,
		array $remainder
	): self
	{
		return new self(
			self::OUTCOME_REPLICATOR,
			$shape,
			$replicatorName,
			$replicator,
			$remainder,
		);
	}

	/**
	 * @param self::OUTCOME_* $outcome
	 */
	public static function stopped(string $outcome, FormShape $shape, string $segment): self
	{
		return new self($outcome, $shape, $segment, null, []);
	}

	/**
	 * @return self::OUTCOME_*
	 */
	public function getOutcome(): string
	{
		return $this->outcome;
	}

	public function isLeaf(): bool
	{
		return $this->outcome === self::OUTCOME_LEAF;
	}

	/**
	 * The shape the walk stopped in - the leaf's own container for OUTCOME_LEAF, the shape holding
	 * the replicator for OUTCOME_REPLICATOR, the shape the offending segment was looked up in
	 * otherwise.
	 */
	public function getShape(): FormShape
	{
		return $this->shape;
	}

	/**
	 * The leaf name for OUTCOME_LEAF, the segment the walk stopped at otherwise - which for
	 * OUTCOME_REPLICATOR is the name the replicator is attached under in getShape(), i.e. what its
	 * recorded component type is keyed by.
	 */
	public function getSegment(): string
	{
		return $this->segment;
	}

	/**
	 * The replicator and every segment after it, for OUTCOME_REPLICATOR - null for every other
	 * outcome. Handing over the WHOLE remainder rather than a single leaf is what makes
	 * `$rep['0-x']` reachable: the row hop is the replicator's decision, not the walk's.
	 *
	 * One accessor rather than three so the remainder's non-emptiness is provable at the call site:
	 * the walk only ever stops at a replicator with segments left, and a consumer that has the
	 * tuple has that fact rather than having to assume it.
	 *
	 * @return array{ReplicatorShape, non-empty-list<string>}|null
	 */
	public function getReplicatorHop(): ?array
	{
		if ($this->replicator === null || $this->remainder === []) {
			return null;
		}

		return [$this->replicator, $this->remainder];
	}

}
