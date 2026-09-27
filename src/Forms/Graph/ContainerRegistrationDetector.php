<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use function count;
use function is_string;
use function spl_object_id;

/**
 * What a container method's own body registers on `$this`, read syntactically.
 *
 * This is the detector both halves of the analysed/vendor split are decided from, and it deliberately
 * answers less than the walk does. It reads no types, resolves no receiver and follows no callee: the
 * only receiver it accepts is `$this`, because that is the one a declaration can name without a
 * scope. That is what lets the same answer be given for an ANALYSED method (where a rule asks whether
 * the registration would survive extraction into a package) and for a VENDOR one (where the walk asks
 * whether its own arg-0 name convention actually describes the body).
 *
 * Reading a vendor body to DISPROVE the convention is not the same as reading it to resolve the
 * registration — the tag stays the only channel that resolves. A body this cannot read produces no
 * sites at all, and both consumers then leave the answer exactly as they found it.
 *
 * The three spellings are the ones Container itself offers:
 *
 *     $this[$name] = new X;
 *     $this->addComponent(new X, $name);
 *     $this->addThing($name);          // any add* the name gate admits, plus offsetSet
 */
final class ContainerRegistrationDetector
{

	private const BRANCHING_NODES = [
		If_::class,
		Switch_::class,
		Foreach_::class,
		For_::class,
		While_::class,
		Do_::class,
		TryCatch::class,
		Ternary::class,
		Match_::class,
	];

	/**
	 * @param array<Node>|null $stmts the method's statements, null for a body there is none of (an
	 *     abstract or interface declaration) — as against an empty body, which registers nothing
	 * @param array<string, int> $parameterIndexes the declaration's own parameter names
	 * @return list<DetectedRegistration>
	 */
	public function registrations(?array $stmts, array $parameterIndexes): array
	{
		if ($stmts === null || $stmts === []) {
			return [];
		}

		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		$branchInnerIds = $this->branchInnerNodeIds($stmts);

		$registrations = [];
		foreach ((new NodeFinder())->find($stmts, static fn (Node $n): bool => $n instanceof Assign
			|| $n instanceof MethodCall
			|| $n instanceof NullsafeMethodCall) as $node) {
			if (isset($closureInnerIds[spl_object_id($node)])) {
				continue;
			}

			$site = $this->nameExpressionOf($node);
			if ($site === null) {
				continue;
			}

			$parameter = $this->nameParameter($site[0], $parameterIndexes);
			$registrations[] = new DetectedRegistration(
				$parameter,
				$parameter === null ? null : $parameterIndexes[$parameter],
				isset($branchInnerIds[spl_object_id($node)]),
			);
		}

		return $registrations;
	}

	/**
	 * The expression naming the registered component, wrapped in a one-element list so "registers
	 * under a name this cannot read" (`[null]`) stays distinguishable from "not a registration at all"
	 * (`null`) — the difference between a body that loses information and one that has none to lose.
	 *
	 * @return array{Expr|null}|null
	 */
	private function nameExpressionOf(Node $node): ?array
	{
		if ($node instanceof Assign) {
			return $node->var instanceof ArrayDimFetch && $this->isThis($node->var->var)
				? [$node->var->dim]
				: null;
		}

		if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
			return null;
		}

		// A first-class callable REFERENCES the adder without running it; whatever registers does so
		// wherever the callable is later invoked, which is not this body.
		if (!$this->isThis($node->var) || !$node->name instanceof Identifier || $node->isFirstClassCallable()) {
			return null;
		}

		$method = $node->name->toString();
		$args = $node->getArgs();

		// addComponent(IComponent $component, ?string $name) is the one registering signature whose
		// name is not argument 0, and it is also an add* name, so it has to be answered first.
		if ($method === 'addComponent') {
			return [isset($args[1]) && !$args[1]->unpack ? $args[1]->value : null];
		}

		if (!RegisteringMethodName::matches($method)) {
			return null;
		}

		return [isset($args[0]) && !$args[0]->unpack ? $args[0]->value : null];
	}

	/**
	 * @param array<string, int> $parameterIndexes
	 */
	private function nameParameter(?Expr $nameExpr, array $parameterIndexes): ?string
	{
		if (!$nameExpr instanceof Variable || !is_string($nameExpr->name)) {
			return null;
		}

		return isset($parameterIndexes[$nameExpr->name]) ? $nameExpr->name : null;
	}

	private function isThis(Expr $expr): bool
	{
		return $expr instanceof Variable && $expr->name === 'this';
	}

	/**
	 * Every node lying inside a branch, a loop or a try — the registrations that are not simply
	 * reached. A conditional registration is what a build method looks like and what no single tag
	 * could summarise, so the discriminator refuses it rather than asking for a tag that would lie.
	 *
	 * @param array<Node> $stmts
	 * @return array<int, true>
	 */
	private function branchInnerNodeIds(array $stmts): array
	{
		$finder = new NodeFinder();

		$ids = [];
		foreach ($finder->find($stmts, static function (Node $n): bool {
			foreach (self::BRANCHING_NODES as $class) {
				if ($n instanceof $class) {
					return true;
				}
			}

			return false;
		}) as $branching) {
			foreach ($finder->findInstanceOf([$branching], Node::class) as $inner) {
				if ($inner !== $branching) {
					$ids[spl_object_id($inner)] = true;
				}
			}
		}

		return $ids;
	}

	/**
	 * @return array<string, int>
	 */
	public static function parameterIndexes(FunctionLike $function): array
	{
		$indexes = [];
		foreach ($function->getParams() as $index => $param) {
			if ($param->var instanceof Variable && is_string($param->var->name)) {
				$indexes[$param->var->name] = (int) $index;
			}
		}

		return $indexes;
	}

	/**
	 * The one registration a method could be summarised by a single @form-adds tag: exactly one, not
	 * conditional, and named by one of the method's own parameters. Null for everything else — several
	 * components, a literal name, or a registration some path may skip — which is the build-method
	 * shape a tag cannot express.
	 *
	 * @param list<DetectedRegistration> $registrations
	 */
	public static function annotatableRegistration(array $registrations): ?DetectedRegistration
	{
		if (count($registrations) !== 1) {
			return null;
		}

		$only = $registrations[0];

		return !$only->isConditional() && $only->getNameParameter() !== null ? $only : null;
	}

	/**
	 * Whether the body registers AT ALL, conditionally or not.
	 *
	 * This used to demand at least one registration every path reaches, which was never a statement
	 * about conditionality: it was how "except `Container::getComponent()`" was spelled without being
	 * able to say so. That method registers inside an `if` guarding a name that is not there yet, and
	 * it is the very read the absence rule judges, so following it deleted correct reports. Now that
	 * ContainerLazyRead answers per CALL whether that lazy read attaches anything, the exception is
	 * derived where it belongs and this half of the gate can be what it says it is.
	 *
	 * A conditional registration is not a reason to refuse a descent, because the descent does not
	 * assume the registration happened: the nested walk meets presences at its own joins, so a
	 * component added inside one arm comes back MAYBE rather than HAPPENS.
	 *
	 * @param list<DetectedRegistration> $registrations
	 */
	public static function registersAnything(array $registrations): bool
	{
		return $registrations !== [];
	}

	/**
	 * Whether the walk's own convention — one component, named by the call's first argument —
	 * describes this body. False when the body registers under a name the convention would misread, or
	 * registers more than the convention accounts for.
	 *
	 * @param list<DetectedRegistration> $registrations
	 */
	public static function matchesFirstArgumentConvention(array $registrations): bool
	{
		return count($registrations) === 1
			&& !$registrations[0]->isConditional()
			&& $registrations[0]->getNameParameterIndex() === 0;
	}

}
