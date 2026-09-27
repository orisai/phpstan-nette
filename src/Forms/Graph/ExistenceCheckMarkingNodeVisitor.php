<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;
use function in_array;

final class ExistenceCheckMarkingNodeVisitor extends NodeVisitorAbstract
{

	public const ATTRIBUTE = 'formShapeExistenceChecked';

	public function enterNode(Node $node): ?Node
	{
		if ($node instanceof Isset_) {
			foreach ($node->vars as $var) {
				$this->markChain($var);
			}
		} elseif ($node instanceof Empty_) {
			$this->markChain($node->expr);
		} elseif ($node instanceof Coalesce) {
			$this->markChain($node->left);
		} elseif ($node instanceof CoalesceAssign) {
			$this->markChain($node->var);
		} elseif (
			($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
			&& !$node->isFirstClassCallable()
			&& $node->name instanceof Identifier
			&& $node->name->toString() === 'removeComponent'
		) {
			// removeComponent($form->getComponent('x') / $form['x']) reads the child that still
			// exists at that point, before the removal; the shape is already the post-removal
			// shape, so exempt the removal target from the does-not-exist report.
			foreach ($node->getArgs() as $arg) {
				$this->markComponentAccess($arg->value);
			}
		}

		return null;
	}

	private function markComponentAccess(Expr $expr): void
	{
		if ($expr instanceof ArrayDimFetch) {
			$expr->setAttribute(self::ATTRIBUTE, true);
		} elseif (
			($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
			&& $expr->name instanceof Identifier
			&& in_array($expr->name->toString(), ['getComponent', 'offsetGet'], true)
		) {
			$expr->setAttribute(self::ATTRIBUTE, true);
		}
	}

	private function markChain(Expr $expr): void
	{
		while ($expr instanceof ArrayDimFetch || $expr instanceof PropertyFetch) {
			$expr->setAttribute(self::ATTRIBUTE, true);
			$expr = $expr->var;
		}
	}

}
