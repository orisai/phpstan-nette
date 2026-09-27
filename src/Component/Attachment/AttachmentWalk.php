<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component\Attachment;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use function array_merge;
use function is_string;
use function spl_object_id;

/**
 * One body, walked statement by statement, answering which method calls in it have a receiver that
 * is PROVABLY not attached to any parent.
 *
 * The state and its transitions are slice 1's; this adds the traversal they compose into and the
 * one extra judgement a report needs, which is whether the state at the top of a statement still
 * holds where the call inside it runs.
 *
 * ## Why a report may be trusted at all
 *
 * A definite answer survives in AttachmentState only for a reference that never escaped: a store
 * into a property, an argument to an unread callee, an alias, a by-reference binding are all
 * mentions no transition site accounts for, and an unaccounted mention degrades. So a reference the
 * state still calls No is one whose whole story this walk has seen, and the only way to move it is
 * to mention it. That is what makes the per-statement test below sufficient: a statement that
 * mentions the receiver EXACTLY ONCE - as the receiver of the call being judged - cannot have
 * attached it before the call runs, whatever else it does.
 *
 * ## Where a block is entered from
 *
 * | construct | its blocks are entered at |
 * | --- | --- |
 * | `if` / `elseif` / `else` | the state after the `if` header, advanced through each elseif CONDITION in turn - those run only once the arms above them were skipped, and they are no part of the header |
 * | `while`, `do`, `for`, `foreach` | the pre-state degraded by everything the whole construct mentions - the second iteration enters from the body's own exit, not from the pre-state, and only a widening covers both |
 * | `switch` | the same widening, because PHP cases FALL THROUGH: a case body may be entered from the case above it |
 * | `try` / `catch` / `finally` | the same widening, because a `try` can be left from any point inside it |
 *
 * A construction inside such a block re-establishes No immediately, so the widening costs only the
 * case where the construction is OUTSIDE the block and the access INSIDE it.
 *
 * The exit state is `AttachmentState::join()` over the pre-state and every block's exit. The
 * pre-state is always included and no arm is ever treated as non-reaching: adding a path to a meet
 * can only weaken the answer, so an over-large reaching set is sound and needs no terminator
 * analysis to be trusted.
 *
 * ## What is never judged
 *
 * Closure and arrow-function interiors, which run at a time this walk does not know. Each is
 * visited on its own as a function-like, from the initial state, so a construction and an access
 * INSIDE one still report; what cannot cross the boundary is the enclosing body's state.
 */
final class AttachmentWalk
{

	/** @var array<string, true> */
	private array $methodNames;

	/** @var list<MethodCall> */
	private array $found = [];

	/**
	 * @param array<string, true> $methodNames
	 */
	private function __construct(array $methodNames)
	{
		$this->methodNames = $methodNames;
	}

	/**
	 * The calls in $stmts whose receiver is provably detached, in source order.
	 *
	 * @param array<Node\Stmt> $stmts
	 * @param array<string, true> $methodNames the call names worth judging
	 * @return list<MethodCall>
	 */
	public static function detachedReceiverCalls(array $stmts, array $methodNames): array
	{
		$walk = new self($methodNames);

		// The gate that keeps this off the vast majority of bodies: no candidate call, no state to
		// build. It is one short-circuiting pass against the per-statement passes it saves.
		if (!$walk->mentionsACandidate($stmts)) {
			return [];
		}

		$walk->walkStmts(AttachmentState::initial(), $stmts);

		return $walk->found;
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 */
	private function mentionsACandidate(array $stmts): bool
	{
		return (new NodeFinder())->findFirst(
			$stmts,
			fn (Node $node): bool => $node instanceof MethodCall
				&& $node->name instanceof Identifier
				&& isset($this->methodNames[$node->name->toString()]),
		) !== null;
	}

	/**
	 * @param array<Node> $nodes
	 */
	private function walkStmts(AttachmentState $state, array $nodes): AttachmentState
	{
		foreach ($nodes as $node) {
			$state = $this->walkStmt($state, $node);
		}

		return $state;
	}

	private function walkStmt(AttachmentState $state, Node $stmt): AttachmentState
	{
		if ($stmt instanceof If_) {
			return $this->walkIf($state, $stmt);
		}

		if ($stmt instanceof Switch_) {
			$blocks = [];
			foreach ($stmt->cases as $case) {
				$blocks[] = $case->cond === null
					? $case->stmts
					: array_merge([$case->cond], $case->stmts);
			}

			return $this->walkWidened($state, $stmt, $blocks);
		}

		if ($stmt instanceof TryCatch) {
			$blocks = [$stmt->stmts];
			foreach ($stmt->catches as $catch) {
				$blocks[] = $catch->stmts;
			}

			if ($stmt->finally !== null) {
				$blocks[] = $stmt->finally->stmts;
			}

			return $this->walkWidened($state, $stmt, $blocks);
		}

		if (
			$stmt instanceof While_
			|| $stmt instanceof Do_
			|| $stmt instanceof For_
			|| $stmt instanceof Foreach_
		) {
			return $this->walkWidened($state, $stmt, [$stmt->stmts]);
		}

		$this->collect($state, $stmt);

		return AttachmentTransitions::apply($state, $stmt);
	}

	private function walkIf(AttachmentState $state, If_ $stmt): AttachmentState
	{
		$this->collect($state, $stmt);
		$header = AttachmentTransitions::apply($state, $stmt);

		$reaching = [$state, $this->walkStmts($header, $stmt->stmts)];

		// An arm past the first is reached only once the conditions above it have RUN and answered
		// false, and those conditions are no part of the header the transitions read. Advancing
		// through them one at a time is what separates `elseif ($this->attach($c))`, which may have
		// given $c a parent by the time the arm runs, from `elseif ($c->isRequired())`, which
		// cannot.
		$skipped = $header;
		foreach ($stmt->elseifs as $elseif) {
			$this->collect($skipped, $elseif->cond);
			$skipped = AttachmentTransitions::apply($skipped, $elseif->cond);
			$reaching[] = $this->walkStmts($skipped, $elseif->stmts);
		}

		if ($stmt->else !== null) {
			$reaching[] = $this->walkStmts($skipped, $stmt->else->stmts);
		}

		return AttachmentState::join($reaching);
	}

	/**
	 * @param list<array<Node>> $blocks
	 */
	private function walkWidened(AttachmentState $state, Node $stmt, array $blocks): AttachmentState
	{
		$this->collect($state, $stmt);
		$header = AttachmentTransitions::apply($state, $stmt);
		$entry = self::degradeMentioned($header, $stmt);

		$reaching = [$state];
		foreach ($blocks as $block) {
			$reaching[] = $this->walkStmts($entry, $block);
		}

		return AttachmentState::join($reaching);
	}

	/**
	 * Everything $node mentions, unproven. A variable-variable names a reference this cannot read,
	 * so the whole state goes rather than one name.
	 */
	private static function degradeMentioned(AttachmentState $state, Node $node): AttachmentState
	{
		foreach ((new NodeFinder())->findInstanceOf([$node], Variable::class) as $variable) {
			if (!is_string($variable->name)) {
				return AttachmentState::initial();
			}

			$state = $state->withAttachment($variable->name, Certainty::UNKNOWN);
		}

		return $state;
	}

	/**
	 * The judgeable calls among the expressions this statement evaluates OUTRIGHT - the same parts
	 * the transitions read, so a construct whose arms are walked separately contributes only its
	 * header here.
	 */
	private function collect(AttachmentState $state, Node $statement): void
	{
		$parts = AttachmentTransitions::evaluatedParts($statement);
		if ($parts === []) {
			return;
		}

		$finder = new NodeFinder();
		$nodes = $finder->find($parts, static fn (Node $node): bool => true);

		$deferred = [];
		$mentions = [];
		foreach ($nodes as $node) {
			if ($node instanceof Variable && is_string($node->name)) {
				$mentions[$node->name] = ($mentions[$node->name] ?? 0) + 1;

				continue;
			}

			if (!$node instanceof Closure && !$node instanceof ArrowFunction) {
				continue;
			}

			foreach ($finder->find($node, static fn (Node $inner): bool => true) as $inner) {
				if ($inner !== $node) {
					$deferred[spl_object_id($inner)] = true;
				}
			}
		}

		foreach ($nodes as $node) {
			if (!$node instanceof MethodCall || isset($deferred[spl_object_id($node)])) {
				continue;
			}

			if (!$node->name instanceof Identifier || $node->isFirstClassCallable()) {
				continue;
			}

			if (!isset($this->methodNames[$node->name->toString()])) {
				continue;
			}

			if (self::receiverIsDetached($state, $node->var, $mentions)) {
				$this->found[] = $node;
			}
		}
	}

	/**
	 * @param array<string, int> $mentions
	 */
	private static function receiverIsDetached(
		AttachmentState $state,
		Node\Expr $receiver,
		array $mentions
	): bool
	{
		// A construction called on directly answers without the state: the object has no other name,
		// so nothing in this statement or any earlier one can have handed it a parent. The
		// zero-argument restriction is slice 1's and for its reason - a constructor handed a
		// container may attach itself, and `new Form($parent, $name)` is the vendor's own spelling
		// of exactly that.
		if ($receiver instanceof New_) {
			return $receiver->getRawArgs() === [];
		}

		if (!$receiver instanceof Variable || !is_string($receiver->name)) {
			return false;
		}

		// A second mention in the same statement is an escape, and one of the shapes an escape takes
		// is `$container->addComponent($c, 'n') && $c->getForm()` - attached by the time the call
		// this would judge runs, while the state at the top of the statement still says No.
		if (($mentions[$receiver->name] ?? 0) !== 1) {
			return false;
		}

		return $state->isDetached($receiver->name);
	}

}
