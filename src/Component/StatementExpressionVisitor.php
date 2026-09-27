<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class StatementExpressionVisitor extends NodeVisitorAbstract
{

	public const AttributeName = 'appStatementExpression';

	public function enterNode(Node $node): ?Node
	{
		if ($node instanceof Node\Stmt\Expression) {
			$node->expr->setAttribute(self::AttributeName, true);
		}

		return null;
	}

}
