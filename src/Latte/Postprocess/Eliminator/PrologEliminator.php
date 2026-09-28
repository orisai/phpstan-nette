<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeVisitor;
use function count;

final class PrologEliminator extends EliminatorVisitor
{

	public function describePattern(): string
	{
		return 'return get_defined_vars() -> return []; drop getParentName() extends-guard; '
			. 'drop prepare() overwrite-warning foreach; drop prepare() when it becomes empty';
	}

	/**
	 * @return Node|int|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof Return_) {
			return $this->simplifyReturn($node);
		}

		if ($node instanceof If_ && $this->isExtendsGuard($node)) {
			return NodeVisitor::REMOVE_NODE;
		}

		if ($node instanceof If_ && $this->isOverwriteWarningGuard($node)) {
			return NodeVisitor::REMOVE_NODE;
		}

		if ($node instanceof ClassMethod && $node->name->toString() === 'lattePrepare' && $node->stmts === []) {
			return NodeVisitor::REMOVE_NODE;
		}

		return null;
	}

	private function simplifyReturn(Return_ $node): ?Return_
	{
		if (!$this->isGetDefinedVarsCall($node->expr)) {
			return null;
		}

		$node->expr = new Array_([]);

		return $node;
	}

	private function isExtendsGuard(If_ $node): bool
	{
		if ($node->else !== null || $node->elseifs !== []) {
			return false;
		}

		if (count($node->stmts) !== 1 || !$node->stmts[0] instanceof Return_) {
			return false;
		}

		return $this->isThisMethodCall($node->cond, 'getParentName');
	}

	private function isOverwriteWarningGuard(If_ $node): bool
	{
		if ($node->else !== null || $node->elseifs !== []) {
			return false;
		}

		if (count($node->stmts) !== 1 || !$node->stmts[0] instanceof Foreach_) {
			return false;
		}

		return $this->isOverwriteWarningForeach($node->stmts[0]);
	}

	private function isOverwriteWarningForeach(Foreach_ $foreach): bool
	{
		if (!$foreach->expr instanceof FuncCall || !$this->isFuncCallNamed($foreach->expr, 'array_intersect_key')) {
			return false;
		}

		if (count($foreach->stmts) !== 1) {
			return false;
		}

		$inner = $foreach->stmts[0];

		return $inner instanceof Expression
			&& $inner->expr instanceof FuncCall
			&& $this->isFuncCallNamed($inner->expr, 'trigger_error');
	}

	private function isGetDefinedVarsCall(?Node $node): bool
	{
		if (!$node instanceof FuncCall || !$this->isFuncCallNamed($node, 'get_defined_vars')) {
			return false;
		}

		return count($node->args) === 0;
	}

	private function isFuncCallNamed(FuncCall $node, string $name): bool
	{
		return $node->name instanceof Name && $node->name->toString() === $name;
	}

	private function isThisMethodCall(Node $node, string $method): bool
	{
		return $node instanceof MethodCall
			&& $node->var instanceof Variable
			&& $node->var->name === 'this'
			&& $node->name instanceof Node\Identifier
			&& $node->name->toString() === $method
			&& count($node->args) === 0;
	}

}
