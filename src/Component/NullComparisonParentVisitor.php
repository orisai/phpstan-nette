<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\NodeVisitorAbstract;

/**
 * Tags expressions that are one side of a `=== null` or `!== null` comparison
 * with the parent BinaryOp under a custom attribute. Rules can use this to
 * fold the comparison into a different rewrite, e.g.
 * `$container->getComponent('x', false) !== null` -> `isset($container['x'])`.
 *
 * Uses a custom attribute name to avoid PHPStan's
 * `phpParser.nodeConnectingAttribute` deprecation warning.
 */
final class NullComparisonParentVisitor extends NodeVisitorAbstract
{

	public const AttributeName = 'appNullComparisonParent';

	public function enterNode(Node $node): ?Node
	{
		if (!$node instanceof Identical && !$node instanceof NotIdentical) {
			return null;
		}

		if (self::isNullLiteral($node->right)) {
			$node->left->setAttribute(self::AttributeName, $node);
		} elseif (self::isNullLiteral($node->left)) {
			$node->right->setAttribute(self::AttributeName, $node);
		}

		return null;
	}

	private static function isNullLiteral(Node\Expr $expr): bool
	{
		return $expr instanceof ConstFetch && $expr->name->toLowerString() === 'null';
	}

}
