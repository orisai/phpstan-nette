<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use OriPhpstan\Nette\Forms\Inference\EventPropertyName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use function is_string;

/**
 * Callables handed to a Nette event property — `$form->onSuccess[] = …`, `$button->onClick = […]`.
 *
 * A store is not a call, so PHPStan's invocation-timing trinary never sees one: that trinary reads
 * its answer off the PARAMETER a callable argument lands on, and an assignment to an array element
 * of a property fetch has no parameter to read. What a stored callable does to a form is deferred
 * relative to the builder all the same, which is why the walk has to learn about it from the
 * assignment grammar instead.
 */
final class EventCallbackStore
{

	private function __construct()
	{
	}

	/**
	 * @param array<Node>|Node $nodes
	 * @return list<array{receiver: string|null, callable: Expr}>
	 */
	public static function inNode($nodes): array
	{
		$found = [];
		foreach ((new NodeFinder())->findInstanceOf($nodes, Assign::class) as $assign) {
			$target = $assign->var;
			$appended = $target instanceof ArrayDimFetch;
			if ($target instanceof ArrayDimFetch) {
				$target = $target->var;
			}

			if (
				!$target instanceof PropertyFetch
				|| !$target->name instanceof Identifier
				|| !EventPropertyName::matches($target->name->toString())
			) {
				continue;
			}

			$receiver = $target->var instanceof Variable && is_string($target->var->name)
				? $target->var->name
				: null;

			// `$form->onSuccess = [$a, $b]` replaces the whole handler list, so every item is one
			// callable; `$form->onSuccess[] = [$this, 'm']` appends a single one, and that array IS it.
			$callables = !$appended && $assign->expr instanceof Array_
				? self::itemValues($assign->expr)
				: [$assign->expr];

			foreach ($callables as $callable) {
				$found[] = ['receiver' => $receiver, 'callable' => $callable];
			}
		}

		return $found;
	}

	/**
	 * @return list<Expr>
	 */
	private static function itemValues(Array_ $array): array
	{
		$values = [];
		foreach ($array->items as $item) {
			$values[] = $item->value;
		}

		return $values;
	}

}
