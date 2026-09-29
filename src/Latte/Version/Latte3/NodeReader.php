<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Block;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\Expression\AssignNode;
use Latte\Compiler\Nodes\Php\Expression\VariableNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\Scalar\BooleanNode;
use Latte\Compiler\Nodes\Php\Scalar\FloatNode;
use Latte\Compiler\Nodes\Php\Scalar\IntegerNode;
use Latte\Compiler\Nodes\Php\Scalar\InterpolatedStringNode;
use Latte\Compiler\Nodes\Php\Scalar\NullNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\NodeTraverser;
use Latte\Essential\Nodes\CaptureNode;
use Latte\Essential\Nodes\DoNode;
use Latte\Essential\Nodes\ForeachNode;
use Latte\Essential\Nodes\VarNode;
use function array_unshift;
use function get_class;
use function is_string;

// Reads the Latte 2 token-scanner answers off Latte 3 expression nodes.
final class NodeReader
{

	private function __construct()
	{
	}

	// The value of a name written as a literal ({block foo}, {input 'x'}), null for anything dynamic.
	public static function literalName(ExpressionNode $expression): ?string
	{
		if ($expression instanceof StringNode) {
			return $expression->value;
		}

		if ($expression instanceof IntegerNode) {
			return (string) $expression->value;
		}

		return null;
	}

	public static function blockName(Block $block): ?string
	{
		return $block->isDynamic() ? null : self::literalName($block->name);
	}

	/**
	 * @return list<string>
	 */
	public static function variableNames(Node $node): array
	{
		$names = [];
		(new NodeTraverser())->traverse($node, static function (Node $child) use (&$names): void {
			if ($child instanceof VariableNode && is_string($child->name)) {
				$names[] = $child->name;
			}
		});

		return $names;
	}

	// Key and value are collected into one flat set: every name the loop binds is equally valid to
	// declare. Only a bare `{foreach $items as ...}` names its iterable - `$this->rows` or a call is a
	// whole expression, matching WrongVariableNameInVarTagRule's own `$iterateeExpr instanceof
	// Variable` gate.

	/**
	 * @return list<string>
	 */
	public static function foreachBindings(ForeachNode $node): array
	{
		$bound = $node->key === null ? [] : self::variableNames($node->key);
		foreach (self::variableNames($node->value) as $name) {
			$bound[] = $name;
		}

		if ($node->expression instanceof VariableNode && is_string($node->expression->name)) {
			array_unshift($bound, $node->expression->name);
		}

		return $bound;
	}

	/**
	 * @return list<string>
	 */
	public static function assignedNames(VarNode $node): array
	{
		$names = [];
		foreach ($node->assignments as $assignment) {
			foreach (self::variableNames($assignment->var) as $name) {
				$names[] = $name;
			}
		}

		return $names;
	}

	public static function capturedVariable(CaptureNode $node): ?string
	{
		return self::variableNames($node->variable)[0] ?? null;
	}

	// Anything that is not a plain `$name = ...` binds an unresolvable set (a list assignment, a
	// property write, a bare call, a compound assignment).
	public static function simpleAssignTarget(DoNode $node): ?string
	{
		$expression = $node->expression;
		if (get_class($expression) !== AssignNode::class) {
			return null;
		}

		return $expression->var instanceof VariableNode && is_string($expression->var->name)
			? $expression->var->name
			: null;
	}

	// The Latte 2 literal classification of a {var} value: a single literal token or an array
	// opener, anything composite is mixed. A synthetic NullNode (no position) is a value-less `{var $x}`.
	public static function literalType(ExpressionNode $expression): ?string
	{
		if ($expression instanceof NullNode && $expression->position === null) {
			return null;
		}

		if ($expression instanceof ArrayNode) {
			return 'array';
		}

		if ($expression instanceof IntegerNode) {
			return 'int';
		}

		if ($expression instanceof FloatNode) {
			return 'float';
		}

		if ($expression instanceof StringNode || $expression instanceof InterpolatedStringNode) {
			return 'string';
		}

		if ($expression instanceof BooleanNode) {
			return 'bool';
		}

		return 'mixed';
	}

}
