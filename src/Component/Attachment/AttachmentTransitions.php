<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component\Attachment;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use function array_merge;
use function array_values;
use function count;
use function is_string;
use function spl_object_id;
use function strtolower;

/**
 * What one statement does to the attachment of the references it mentions, read syntactically and
 * scope-free.
 *
 * The transitions are the ones nette/component-model makes observable, and each is definite only
 * because the vendor method it reads either succeeds completely or throws:
 *
 *     $c = new X();                     No     nothing has been given a parent yet
 *     $p->addComponent($c, 'n');        Yes    a name clash, a circular reference and a refused
 *                                              validateParent() all throw, so a returning call
 *                                              leaves the component in $p->components
 *     $p->removeComponent($c);          No     unsets the slot and calls $c->setParent(null); a
 *                                              component that is not in $p throws instead
 *     $c->setParent(null);              No     the detach branch, with or without a rename
 *     $c = $p->getComponent('n');       Yes    the one-argument form either returns an attached
 *                                              component or throws; it creates and attaches one
 *                                              when the name is free
 *
 * Everything else degrades to UNKNOWN, and the rule that makes the degradation exhaustive rather
 * than a list is this: a reference takes a definite transition from a statement only when the
 * statement mentions it EXACTLY ONCE and that one mention is a recognised site. A second mention -
 * an argument to a callee this cannot read, a store into a property or an array, a closure capture,
 * a by-reference binding, a second hop of the same chain - is an escape, and an escape is exactly
 * the case the design degrades on. It also removes any question of what order two transitions
 * inside one statement apply in: two never do.
 *
 * The one mention that is recognised without moving anything is a RECEIVER: `$c->setDisabled()`,
 * `$c->onSuccess[] = ...` and `$c['child']` cannot re-parent `$c`, because the only method on a
 * component that touches its own parent is setParent(), which is read above. A first-class callable
 * is not a receiver use in this sense - it hands the bound method somewhere else - so it degrades.
 *
 * Reads inside a closure or arrow function are counted as mentions but never recognised as sites:
 * the body runs at a time this walk does not know, so `function () use ($c) { $p->addComponent($c); }`
 * degrades instead of claiming Yes at the point the closure is written.
 *
 * `new X(...)` with arguments degrades, and only the zero-argument form claims No. That is the
 * verified constructor-side fact, not a guess about X: Nette's own component constructors reach a
 * container solely through a parent they are handed (`new Form($parent, $name)` runs
 * `$parent->addComponent($this, $name)` inside an `if ($parent !== null)`), so a construction that
 * hands over nothing cannot have attached. A constructor that reaches a container from somewhere
 * other than its own arguments would defeat this, and is the residual assumption a later slice can
 * discharge by reading the constructor body instead of counting its arguments.
 */
final class AttachmentTransitions
{

	public const ADD_COMPONENT = 'addComponent';

	public const GET_COMPONENT = 'getComponent';

	public const REMOVE_COMPONENT = 'removeComponent';

	public const SET_PARENT = 'setParent';

	/**
	 * Not a Certainty: it marks a mention that is accounted for and moves nothing, which is
	 * different from a mention that proves MAYBE.
	 */
	private const INERT = 'inert';

	private function __construct()
	{
	}

	public static function apply(AttachmentState $state, Node $statement): AttachmentState
	{
		/** @var array<int, string> $sites */
		$sites = [];
		/** @var array<string, list<int>> $mentions */
		$mentions = [];
		$dynamicReference = false;

		$parts = self::evaluatedParts($statement);
		$rootIds = self::rootExpressionIds($parts);
		$nodes = self::allNodes($parts);
		$deferredIds = self::deferredNodeIds($nodes);

		foreach ($nodes as $node) {
			if ($node instanceof Variable) {
				if (is_string($node->name)) {
					$mentions[$node->name][] = spl_object_id($node);
				} else {
					$dynamicReference = true;
				}

				continue;
			}

			if (isset($deferredIds[spl_object_id($node)])) {
				continue;
			}

			foreach (self::transitionSites($node, $rootIds) as $site) {
				$sites[spl_object_id($site[0])] = $site[1];
			}
		}

		// A variable-variable names a reference this cannot read, so the statement may have moved
		// anything the state holds.
		if ($dynamicReference) {
			return AttachmentState::initial();
		}

		foreach ($mentions as $name => $nodeIds) {
			$transition = self::transitionOf($nodeIds, $sites);
			if ($transition !== self::INERT) {
				$state = $state->withAttachment($name, $transition);
			}
		}

		return $state;
	}

	/**
	 * The one transition a statement's mentions of a reference amount to. A mention no site accounts
	 * for is an escape and answers UNKNOWN for the whole statement; mentions that are all inert move
	 * nothing; and two definite sites in one statement answer UNKNOWN rather than picking an
	 * evaluation order to believe.
	 *
	 * @param list<int> $nodeIds
	 * @param array<int, string> $sites
	 */
	private static function transitionOf(array $nodeIds, array $sites): string
	{
		$definite = [];
		foreach ($nodeIds as $nodeId) {
			if (!isset($sites[$nodeId])) {
				return Certainty::UNKNOWN;
			}

			if ($sites[$nodeId] !== self::INERT) {
				$definite[] = $sites[$nodeId];
			}
		}

		if ($definite === []) {
			return self::INERT;
		}

		return count($definite) === 1 ? $definite[0] : Certainty::UNKNOWN;
	}

	/**
	 * The parts of a statement that are evaluated AT the statement, which for a branching or
	 * looping construct is its header alone. The arms are walked separately and join afterwards, so
	 * reading them here would apply an arm's transition on the path that skips it - the one way a
	 * syntactic pass could hand back a definite answer no path supports.
	 *
	 * Public because a consumer that REPORTS at a program point has to agree with the transitions
	 * about which expressions the point covers. AttachmentWalk reads the same parts to decide which
	 * calls it may judge, so a construct classified as branching here cannot be judged as
	 * straight-line there.
	 *
	 * @return list<Node>
	 */
	public static function evaluatedParts(Node $statement): array
	{
		if (
			$statement instanceof If_
			|| $statement instanceof Switch_
			|| $statement instanceof While_
			|| $statement instanceof Do_
		) {
			return [$statement->cond];
		}

		if ($statement instanceof Foreach_) {
			$parts = [$statement->expr, $statement->valueVar];
			if ($statement->keyVar !== null) {
				$parts[] = $statement->keyVar;
			}

			return $parts;
		}

		if ($statement instanceof For_) {
			return array_values(array_merge($statement->init, $statement->cond, $statement->loop));
		}

		if ($statement instanceof TryCatch) {
			return [];
		}

		return [$statement];
	}

	/**
	 * The expressions a statement runs OUTRIGHT, by node id. An assignment is a transition site only
	 * when it is one of them: `$p->addComponent($c = new X(), 'n')` embeds one whose target the
	 * enclosing call then attaches, and reading the embedded assignment alone would answer No about
	 * a component that is provably attached by the time the statement finishes.
	 *
	 * @param list<Node> $parts
	 * @return array<int, true>
	 */
	private static function rootExpressionIds(array $parts): array
	{
		$ids = [];
		foreach ($parts as $part) {
			$root = $part instanceof Expression ? $part->expr : $part;
			$ids[spl_object_id($root)] = true;
		}

		return $ids;
	}

	/**
	 * Every node under the evaluated parts, the parts themselves included.
	 *
	 * @param list<Node> $parts
	 * @return list<Node>
	 */
	private static function allNodes(array $parts): array
	{
		return array_values((new NodeFinder())->find($parts, static fn (Node $node): bool => true));
	}

	/**
	 * The nodes whose evaluation the statement does not entail, so no transition may be read off
	 * them - the whole interior of a closure or arrow function, which runs whenever someone later
	 * invokes it or never, and the operands of the constructs that short-circuit: both ternary
	 * spellings, the boolean and logical pairs, a coalesce in either form and a match arm.
	 *
	 * A mention inside one still counts. Refusing to CLASSIFY it while counting it is precisely what
	 * turns a conditional attach into a degradation rather than into a claim on paths that skip it.
	 *
	 * @param list<Node> $nodes
	 * @return array<int, true>
	 */
	private static function deferredNodeIds(array $nodes): array
	{
		$finder = new NodeFinder();

		$deferred = [];
		foreach ($nodes as $node) {
			foreach (self::deferredRegions($node) as $region) {
				foreach ($finder->find($region, static fn (Node $inner): bool => true) as $inner) {
					$deferred[spl_object_id($inner)] = true;
				}
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

		return $deferred;
	}

	/**
	 * @return list<Node>
	 */
	private static function deferredRegions(Node $node): array
	{
		if ($node instanceof Ternary) {
			return $node->if === null ? [$node->else] : [$node->if, $node->else];
		}

		if (
			$node instanceof BooleanAnd
			|| $node instanceof BooleanOr
			|| $node instanceof LogicalAnd
			|| $node instanceof LogicalOr
			|| $node instanceof Coalesce
		) {
			return [$node->right];
		}

		if ($node instanceof AssignCoalesce) {
			return [$node->expr];
		}

		return $node instanceof Match_ ? array_values($node->arms) : [];
	}

	/**
	 * @param array<int, true> $rootIds
	 * @return list<array{Variable, string}>
	 */
	private static function transitionSites(Node $node, array $rootIds): array
	{
		if ($node instanceof Assign) {
			$runsOutright = isset($rootIds[spl_object_id($node)]);

			return $runsOutright && $node->var instanceof Variable && is_string($node->var->name)
				? [[$node->var, self::assignedAttachment($node->expr)]]
				: [];
		}

		if (
			$node instanceof PropertyFetch
			|| $node instanceof NullsafePropertyFetch
			|| $node instanceof ArrayDimFetch
		) {
			return self::inertReceiver($node->var);
		}

		if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
			return [];
		}

		if (!$node->name instanceof Identifier || $node->isFirstClassCallable()) {
			return [];
		}

		$method = $node->name->toString();
		$args = $node->getArgs();

		if ($method === self::SET_PARENT) {
			$receiver = self::inertReceiver($node->var);
			if ($receiver === []) {
				return [];
			}

			$parent = $args[0] ?? null;
			$detaches = $parent !== null && !$parent->unpack && self::isNullLiteral($parent->value);

			return [[$receiver[0][0], $detaches ? Certainty::NEVER : Certainty::UNKNOWN]];
		}

		$sites = self::inertReceiver($node->var);

		if ($method === self::ADD_COMPONENT || $method === self::REMOVE_COMPONENT) {
			$subject = isset($args[0]) && !$args[0]->unpack ? $args[0]->value : null;
			if ($subject instanceof Variable && is_string($subject->name)) {
				$sites[] = [$subject, $method === self::ADD_COMPONENT ? Certainty::HAPPENS : Certainty::NEVER];
			}
		}

		return $sites;
	}

	/**
	 * The certainty a variable takes from what is assigned to it. An `$a = $b` between two
	 * references is deliberately NOT an inheritance: the object then answers to two names, and a
	 * later attach through either would leave the other holding a stale definite answer, so the
	 * assignment degrades - the target here, and the source through its own second mention.
	 */
	private static function assignedAttachment(Expr $assigned): string
	{
		if ($assigned instanceof New_) {
			return $assigned->getRawArgs() === [] ? Certainty::NEVER : Certainty::UNKNOWN;
		}

		if (
			($assigned instanceof MethodCall || $assigned instanceof NullsafeMethodCall)
			&& $assigned->name instanceof Identifier
			&& !$assigned->isFirstClassCallable()
			&& $assigned->name->toString() === self::GET_COMPONENT
			&& count($assigned->getArgs()) === 1
			&& !$assigned->getArgs()[0]->unpack
		) {
			return Certainty::HAPPENS;
		}

		// An ArrayDimFetch read lands here rather than beside getComponent(): offsetGet() delegates
		// to it, but nothing syntactic separates `$container['x']` from `$row['x']` on a plain array,
		// and a claim about a value that is not a component at all is the direction this must not
		// take. A consumer that already knows the receiver's type can sharpen it.
		return Certainty::UNKNOWN;
	}

	/**
	 * @return list<array{Variable, string}>
	 */
	private static function inertReceiver(Expr $receiver): array
	{
		return $receiver instanceof Variable && is_string($receiver->name)
			? [[$receiver, self::INERT]]
			: [];
	}

	private static function isNullLiteral(Expr $expr): bool
	{
		return $expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'null';
	}

}
