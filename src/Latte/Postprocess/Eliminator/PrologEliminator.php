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

	public const ROLE_DEFINED_VARS = 'definedVars';

	public const ROLE_EXTENDS_GUARD = 'extendsGuard';

	public const ROLE_OVERWRITE_WARNING = 'overwriteWarning';

	public const ROLE_EMPTY_PREPARE = 'emptyPrepare';

	public function describePattern(): string
	{
		$patterns = $this->patterns();

		return 'return get_defined_vars() -> return []; '
			. ($patterns->has(self::ROLE_EXTENDS_GUARD) ? 'drop getParentName() extends-guard; ' : '')
			. 'drop prepare() overwrite-warning foreach'
			. ($patterns->has(self::ROLE_EMPTY_PREPARE) ? '; drop prepare() when it becomes empty' : '')
			. ': ' . $patterns->describe();
	}

	/**
	 * @return Node|int|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof Return_) {
			return $this->simplifyReturn($node);
		}

		if ($node instanceof If_ && $this->patterns()->has(self::ROLE_EXTENDS_GUARD) && $this->isExtendsGuard($node)) {
			return NodeVisitor::REMOVE_NODE;
		}

		if ($node instanceof If_ && $this->isOverwriteWarningGuard($node)) {
			return NodeVisitor::REMOVE_NODE;
		}

		if (
			$node instanceof ClassMethod
			&& $this->patterns()->hasName(self::ROLE_EMPTY_PREPARE, $node->name->toString())
			&& $node->stmts === []
		) {
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

		return $this->isThisMethodCall($node->cond, $this->patterns()->name(self::ROLE_EXTENDS_GUARD));
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
		[$intersect, $trigger] = $this->patterns()->names(self::ROLE_OVERWRITE_WARNING);
		if (!$foreach->expr instanceof FuncCall || !$this->isFuncCallNamed($foreach->expr, $intersect)) {
			return false;
		}

		if (count($foreach->stmts) !== 1) {
			return false;
		}

		$inner = $foreach->stmts[0];

		return $inner instanceof Expression
			&& $inner->expr instanceof FuncCall
			&& $this->isFuncCallNamed($inner->expr, $trigger);
	}

	private function isGetDefinedVarsCall(?Node $node): bool
	{
		if (
			!$node instanceof FuncCall
			|| !$this->isFuncCallNamed($node, $this->patterns()->name(self::ROLE_DEFINED_VARS))
		) {
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
