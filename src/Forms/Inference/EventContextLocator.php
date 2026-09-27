<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Inference;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;

/**
 * Determines the on* event a node is lexically nested in — e.g. a getValues() call
 * inside `$form->onSuccess[] = function (...) { ... }` is in the 'onSuccess' event.
 * Lets the form-values projection know it runs in a validated context.
 */
final class EventContextLocator
{

	/**
	 * @param array<Node> $ast
	 */
	public static function enclosingEventName(array $ast, Expr $target): ?string
	{
		$handler = self::innermostEnclosingHandler($ast, $target);
		if ($handler === null) {
			return null;
		}

		foreach ((new NodeFinder())->findInstanceOf($ast, Assign::class) as $assign) {
			if ($assign->expr !== $handler) {
				continue;
			}

			$var = $assign->var;
			if ($var instanceof ArrayDimFetch) {
				$var = $var->var;
			}

			if (!$var instanceof PropertyFetch || !$var->name instanceof Identifier) {
				continue;
			}

			$property = $var->name->toString();
			if (EventPropertyName::matches($property)) {
				return $property;
			}
		}

		return null;
	}

	/**
	 * @param array<Node> $ast
	 */
	private static function innermostEnclosingHandler(array $ast, Expr $target): ?Expr
	{
		$pos = $target->getStartFilePos();
		$best = null;
		$bestSpan = null;
		foreach ((new NodeFinder())->findInstanceOf($ast, FunctionLike::class) as $handler) {
			if (!$handler instanceof Closure && !$handler instanceof ArrowFunction) {
				continue;
			}

			$start = $handler->getStartFilePos();
			$end = $handler->getEndFilePos();
			if ($pos < $start || $pos > $end) {
				continue;
			}

			$span = $end - $start;
			if ($bestSpan === null || $span < $bestSpan) {
				$best = $handler;
				$bestSpan = $span;
			}
		}

		return $best;
	}

}
