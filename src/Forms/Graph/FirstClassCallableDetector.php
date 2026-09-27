<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use function assert;

/**
 * A first-class callable (`$x->m(...)`) closes over its receiver: the resulting Closure can
 * invoke the method later with unknown arguments, so on a tracked form/container/control it
 * is an escape, never a call happening at the site.
 */
final class FirstClassCallableDetector
{

	public static function inSubtree(Node $node): bool
	{
		return (new NodeFinder())->findFirst(
			$node,
			static fn (Node $n): bool => $n instanceof CallLike && $n->isFirstClassCallable(),
		) !== null;
	}

	public static function onTrackedReceiver(Node $node, string $trackedName): bool
	{
		foreach ((new NodeFinder())->find(
			$node,
			static fn (Node $n): bool => ($n instanceof MethodCall || $n instanceof NullsafeMethodCall)
				&& $n->isFirstClassCallable(),
		) as $call) {
			assert($call instanceof MethodCall || $call instanceof NullsafeMethodCall);
			$root = MethodChainSpine::rootReceiver($call);
			if ($root instanceof Variable && $root->name === $trackedName) {
				return true;
			}

			if (
				$root instanceof ArrayDimFetch
				&& $root->var instanceof Variable
				&& $root->var->name === $trackedName
			) {
				return true;
			}
		}

		return false;
	}

}
