<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use function count;
use function strlen;
use function substr;

final class EscapingEliminator extends EliminatorVisitor
{

	// Escaping and URL-check calls whose first argument is the printed value.
	public const ROLE_UNWRAP_VALUE = 'unwrapValue';

	// Whole-attribute formatters of a string-like value: the second argument is the printed value.
	public const ROLE_UNWRAP_ATTRIBUTE_VALUE = 'unwrapAttributeValue';

	// Whole-attribute formatters accepting arrays and objects: the value goes through the typed
	// Helpers::html*Attribute() stand-in named after the formatter, so an array-valued attribute is
	// not an echo of an array.
	public const ROLE_ATTRIBUTE_STAND_IN = 'attributeStandIn';

	private const HELPERS_CLASS = Helpers::class;

	// URL-check filters applied as ($this->filters->name)($url).
	public const ROLE_UNWRAP_FILTER = 'unwrapFilter';

	public function describePattern(): string
	{
		return 'unwrap escaping/URL-check calls to their argument, recursively; escapeJs kept (JSON-encodes any value); '
			. 'array-accepting attribute formatters -> Helpers::html*Attribute(value): '
			. $this->patterns()->describe();
	}

	/**
	 * @return Node|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof StaticCall) {
			return $this->unwrapStaticCall($node);
		}

		if ($node instanceof FuncCall && $node->name instanceof PropertyFetch && count($node->args) === 1) {
			return $this->isUnwrappedFilter($node->name) ? $this->argValue($node, 0) : null;
		}

		return null;
	}

	private function unwrapStaticCall(StaticCall $node): ?Expr
	{
		if (!$node->class instanceof Node\Name || !$node->name instanceof Identifier) {
			return null;
		}

		$class = $node->class->toString();
		$method = $node->name->toString();
		$patterns = $this->patterns();

		if ($patterns->isStaticCall(self::ROLE_UNWRAP_VALUE, $class, $method) && count($node->args) === 1) {
			return $this->argValue($node, 0);
		}

		if ($patterns->isStaticCall(self::ROLE_UNWRAP_ATTRIBUTE_VALUE, $class, $method) && count($node->args) >= 2) {
			return $this->argValue($node, 1);
		}

		if ($patterns->isStaticCall(self::ROLE_ATTRIBUTE_STAND_IN, $class, $method) && count($node->args) >= 2) {
			return new StaticCall(
				new FullyQualified(self::HELPERS_CLASS),
				new Identifier('html' . substr($method, strlen('format'))),
				[$node->args[1]],
				$node->getAttributes(),
			);
		}

		return null;
	}

	private function isUnwrappedFilter(PropertyFetch $name): bool
	{
		if (
			!$name->name instanceof Identifier
			|| !$this->patterns()->hasName(self::ROLE_UNWRAP_FILTER, $name->name->toString())
		) {
			return false;
		}

		$filters = $name->var;

		return $filters instanceof PropertyFetch
			&& $filters->name instanceof Identifier
			&& $filters->name->toString() === 'filters'
			&& $filters->var instanceof Variable
			&& $filters->var->name === 'this';
	}

	/**
	 * @param StaticCall|FuncCall $call
	 */
	private function argValue(Expr $call, int $index): ?Expr
	{
		$arg = $call->args[$index] ?? null;

		return $arg instanceof Node\Arg ? $arg->value : null;
	}

}
