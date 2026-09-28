<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeVisitor;
use function array_pop;
use function count;

final class IteratorEliminator extends EliminatorVisitor
{

	private const CACHING_ITERATOR_CLASS = 'Latte\Runtime\CachingIterator';

	private const ITERATOR_TEMP = "\u{29F}_it";

	private const ITERATOR_VAR = 'iterator';

	private const ITERATIONS_VAR = 'iterations';

	private const MARKER_ITERATOR_NEW = 'iteratorEliminator.iteratorNew';

	public function describePattern(): string
	{
		return 'foreach ($iterator = $ʟ_it = new CachingIterator(EXPR, $ʟ_it ?? null) as ...) -> '
			. '$iterator = new CachingIterator(EXPR); foreach (EXPR as ...); drop '
			. '$iterator = $ʟ_it = $ʟ_it->getParent() restores and adjacent $iterations counters '
			. '(plain foreach untouched)';
	}

	/**
	 * @return array<Stmt>|int|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof ClassMethod && $node->stmts !== null) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof Foreach_) {
			$node->stmts = $this->normalizeStmts($node->stmts);

			return $this->rewriteWrappedForeach($node);
		}

		if ($node instanceof Expression && $this->isIteratorRestore($node->expr)) {
			return NodeVisitor::REMOVE_NODE;
		}

		return null;
	}

	/**
	 * @return array<Stmt>|null
	 */
	private function rewriteWrappedForeach(Foreach_ $node): ?array
	{
		$expr = $this->unwrapCachingIteratorExpr($node->expr);
		if ($expr === null) {
			return null;
		}

		$iteratorAssign = new Expression(
			new Assign(
				new Variable(self::ITERATOR_VAR),
				new New_(new FullyQualified(self::CACHING_ITERATOR_CLASS), [new Arg($expr)]),
			),
		);
		$iteratorAssign->setAttribute(self::MARKER_ITERATOR_NEW, true);

		$foreach = new Foreach_($expr, $node->valueVar, [
			'keyVar' => $node->keyVar,
			'byRef' => $node->byRef,
			'stmts' => $this->stripTrailingIterationsIncrement($node->stmts),
		]);

		return [$iteratorAssign, $foreach];
	}

	private function unwrapCachingIteratorExpr(Expr $expr): ?Expr
	{
		if (!$expr instanceof Assign || !$this->isVariableNamed($expr->var, self::ITERATOR_VAR)) {
			return null;
		}

		$inner = $expr->expr;
		if (!$inner instanceof Assign || !$this->isVariableNamed($inner->var, self::ITERATOR_TEMP)) {
			return null;
		}

		$new = $inner->expr;
		if (!$new instanceof New_ || !$this->isCachingIteratorClass($new)) {
			return null;
		}

		if (count($new->args) !== 2 || !$new->args[0] instanceof Arg || !$new->args[1] instanceof Arg) {
			return null;
		}

		if (!$this->isIteratorTempCoalesceNull($new->args[1]->value)) {
			return null;
		}

		return $new->args[0]->value;
	}

	private function isCachingIteratorClass(New_ $new): bool
	{
		return $new->class instanceof Node\Name && $new->class->toString() === self::CACHING_ITERATOR_CLASS;
	}

	private function isIteratorTempCoalesceNull(Expr $expr): bool
	{
		return $expr instanceof Coalesce
			&& $this->isVariableNamed($expr->left, self::ITERATOR_TEMP)
			&& $expr->right instanceof Node\Expr\ConstFetch
			&& $expr->right->name->toString() === 'null';
	}

	private function isIteratorRestore(Expr $expr): bool
	{
		if (!$expr instanceof Assign || !$this->isVariableNamed($expr->var, self::ITERATOR_VAR)) {
			return false;
		}

		$inner = $expr->expr;
		if (!$inner instanceof Assign || !$this->isVariableNamed($inner->var, self::ITERATOR_TEMP)) {
			return false;
		}

		return $inner->expr instanceof MethodCall
			&& $this->isVariableNamed($inner->expr->var, self::ITERATOR_TEMP)
			&& $inner->expr->name instanceof Node\Identifier
			&& $inner->expr->name->toString() === 'getParent'
			&& count($inner->expr->args) === 0;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function stripTrailingIterationsIncrement(array $stmts): array
	{
		$lastIndex = count($stmts) - 1;
		if ($lastIndex < 0) {
			return $stmts;
		}

		$last = $stmts[$lastIndex];
		if ($last instanceof Expression && $this->isPostIncVariable($last->expr, self::ITERATIONS_VAR)) {
			array_pop($stmts);
		}

		return $stmts;
	}

	private function isPostIncVariable(Expr $expr, string $name): bool
	{
		return $expr instanceof PostInc && $this->isVariableNamed($expr->var, $name);
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function normalizeStmts(array $stmts): array
	{
		$result = [];
		$count = count($stmts);

		for ($i = 0; $i < $count; $i++) {
			$stmt = $stmts[$i];
			$next = $stmts[$i + 1] ?? null;

			if ($this->isIterationsReset($stmt) && $next !== null && $this->isMarkedIteratorNew($next)) {
				continue;
			}

			$result[] = $stmt;
		}

		return $result;
	}

	private function isIterationsReset(Stmt $stmt): bool
	{
		return $stmt instanceof Expression
			&& $stmt->expr instanceof Assign
			&& $this->isVariableNamed($stmt->expr->var, self::ITERATIONS_VAR)
			&& $stmt->expr->expr instanceof Int_
			&& $stmt->expr->expr->value === 0;
	}

	private function isMarkedIteratorNew(Stmt $stmt): bool
	{
		return $stmt->getAttribute(self::MARKER_ITERATOR_NEW) === true;
	}

	private function isVariableNamed(Node $node, string $name): bool
	{
		return $node instanceof Variable && $node->name === $name;
	}

}
