<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use OriPhpstan\Nette\Component\Attachment\ContainerLazyRead;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use function is_string;
use function spl_object_id;

/**
 * A child pulled out of the tracked container into a local — `$probe = $form['x']`, or its
 * `$form->getComponent('x')` twin, since offsetGet delegates to getComponent — and what the rest of
 * the walked body then does with that local.
 *
 * The read alone says nothing. `$form->addText('x')->setDisabled()` and
 * `$probe = $form['x']; $probe->setDisabled();` are one component set written two ways, so a rule that
 * looks only for the assignment separates them on spelling: the second loses the form's whole absence
 * reporting while the first keeps it. What decides is the USE — whether anything done through the
 * handle could register a component the walk would then never see.
 *
 * This half is syntactic and reads no types: it answers what is DONE with the handle, never what the
 * handle is. A use it does not recognise is not inert, it is unanswered, and the caller keeps opening.
 *
 * It used to be paired with a caller-side test that the handle's class had no registration surface at
 * all, because the registering-name authority calls `getComponent()` inert while the container method
 * of that name creates and attaches. That was a real hole and it is closed here, where it belongs:
 * a lazy read is refused as a chain hop, so this predicate no longer needs a caller to have proved
 * anything about the class before its answer means what it says.
 *
 * The recognised uses are these, and each covers exactly one occurrence of the local:
 *
 *     $probe = $form['x'];                    // re-read of the tracked container: the old handle dies
 *     assert($probe instanceof BaseControl);  // a type probe passes the object nowhere
 *     $probe->setDisabled();                  // a bare-statement chain of non-registering names
 *
 * A chain has to be a bare statement because a captured result may be the form itself
 * (`$f = $probe->getForm();`), which a later statement could add to under a name this walk would never
 * attribute. Every hop of the chain is checked, not just the first, for the same reason: the receiver
 * of a second hop is whatever the first returned, and `$probe->getForm()->addText('late')` registers.
 */
final class ComponentHandleUses
{

	private function __construct()
	{
	}

	/**
	 * Every `$local = $tracked[…]` / `$local = $tracked->getComponent(…)` inside the nodes.
	 *
	 * @param array<Node> $nodes
	 * @return list<array{assign: Assign, handle: string, childName: string|null}>
	 */
	public static function reads(array $nodes, string $trackedName): array
	{
		$reads = [];
		foreach ((new NodeFinder())->findInstanceOf($nodes, Assign::class) as $assign) {
			if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
				continue;
			}

			$read = self::childNameRead($assign->expr, $trackedName);
			if ($read === null) {
				continue;
			}

			$reads[] = ['assign' => $assign, 'handle' => $assign->var->name, 'childName' => $read[0]];
		}

		return $reads;
	}

	/**
	 * Whether every occurrence of the handle in the walked body is one of the recognised inert uses.
	 * False for a body that uses it in any other way, and false for a name with no occurrence at all
	 * (the caller's own read is one, so an empty answer means the two are not looking at the same
	 * body and nothing has been proven).
	 *
	 * @param array<Node> $stmts
	 */
	public static function everyUseIsInert(array $stmts, string $handleName, string $trackedName): bool
	{
		$finder = new NodeFinder();

		$occurrences = [];
		foreach ($finder->findInstanceOf($stmts, Variable::class) as $variable) {
			if ($variable->name === $handleName) {
				$occurrences[spl_object_id($variable)] = true;
			}
		}

		if ($occurrences === []) {
			return false;
		}

		$inert = [];

		foreach (self::reads($stmts, $trackedName) as $read) {
			if ($read['handle'] === $handleName) {
				$inert[spl_object_id($read['assign']->var)] = true;
			}
		}

		foreach ($finder->findInstanceOf($stmts, Instanceof_::class) as $probe) {
			if ($probe->expr instanceof Variable && $probe->expr->name === $handleName) {
				$inert[spl_object_id($probe->expr)] = true;
			}
		}

		foreach ($finder->findInstanceOf($stmts, Expression::class) as $expression) {
			$root = self::nonRegisteringChainRoot($expression->expr, $handleName);
			if ($root !== null) {
				$inert[spl_object_id($root)] = true;
			}
		}

		foreach ($occurrences as $id => $_unused) {
			if (!isset($inert[$id])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The name the expression reads off the tracked container, wrapped in a one-element list so a read
	 * under a name this cannot resolve (`[null]`) stays distinguishable from an expression that is not
	 * a read of the tracked container at all (`null`) — the difference between a handle whose origin is
	 * unknown and no handle.
	 *
	 * @return array{string|null}|null
	 */
	private static function childNameRead(Expr $expr, string $trackedName): ?array
	{
		if ($expr instanceof ArrayDimFetch) {
			if (!$expr->var instanceof Variable || $expr->var->name !== $trackedName) {
				return null;
			}

			return [$expr->dim instanceof String_ ? $expr->dim->value : null];
		}

		if (
			!$expr instanceof MethodCall
			|| !$expr->var instanceof Variable
			|| $expr->var->name !== $trackedName
			|| !$expr->name instanceof Identifier
			|| $expr->name->toString() !== 'getComponent'
		) {
			return null;
		}

		$arg = $expr->getArgs()[0] ?? null;

		return [$arg !== null && !$arg->unpack && $arg->value instanceof String_ ? $arg->value->value : null];
	}

	/**
	 * The handle at the root of a method-call chain none of whose hops registers a component, or null
	 * when the expression is not such a chain. A dynamic method name and a first-class callable are
	 * both refused: neither states what is called, and the second states only that it is called
	 * somewhere else.
	 *
	 * A LAZY READ is refused too, and it is the reason this predicate is sound on its own rather than
	 * only under a caller that has first proved the handle cannot register. `getComponent()` is not an
	 * add* name, so the registering-name authority calls it inert — correctly, since what it names is
	 * a READ — but on a container the read creates and attaches whatever the name is missing. Nothing
	 * here knows the handle's own children, so the read is left unanswered rather than proven inert;
	 * ContainerLazyRead is what a consumer that DOES know them would answer it with.
	 */
	private static function nonRegisteringChainRoot(Expr $expr, string $handleName): ?Variable
	{
		while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
			if (
				!$expr->name instanceof Identifier
				|| $expr->isFirstClassCallable()
				|| RegisteringMethodName::matches($expr->name->toString())
				|| ContainerLazyRead::isLazyRead($expr->name->toString())
			) {
				return null;
			}

			$expr = $expr->var;
		}

		return $expr instanceof Variable && $expr->name === $handleName ? $expr : null;
	}

}
