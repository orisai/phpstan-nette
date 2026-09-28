<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeVisitor;
use function array_slice;
use function array_unshift;
use function count;
use function is_string;
use function strpos;

final class ControlFlowEliminator extends EliminatorVisitor
{

	private const ITERATOR_TEMP = "\u{29F}_it";

	private const TRY_TEMP = "\u{29F}_try";

	private const SWITCH_TEMP = "\u{29F}_switch";

	private const EXCEPTION_TEMP = "\u{29F}_e";

	private const LOC_TEMP = "\u{29F}_loc";

	private const TAG_IF_TEMP = "\u{29F}_if";

	private const IF_CAPTURE_PREFIX = "\u{29F}_if"; // Bare-Variable match only; ʟ_ifc is always ArrayDimFetch-wrapped, so the ifc prefix collision cannot fire.

	private const SWITCH_RENAME = 'latteSwitch';

	private const EXCEPTION_RENAME = 'latteException';

	public function describePattern(): string
	{
		return 'capture-form {if} ob_start/try/finally shell -> plain if (COND) { BODY }; '
			. '$ʟ_switch temp renamed (if/elseif in_array chain kept); {try}/{rollback} ob_start/$ʟ_try '
			. 'stash+restore dropped, catch (\Throwable $ʟ_e) renamed to $latteException, finally dropped; '
			. '{ifchanged} $ʟ_loc compare shell -> if (true) { compared expr assigned; BODY }; '
			. 'n:tag-if $ʟ_if[n] temp renamed';
	}

	/**
	 * @return Node|int|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof ClassMethod && $node->stmts !== null) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof If_) {
			$node->stmts = $this->normalizeStmts($node->stmts);

			$ifchanged = $this->rebuildIfchanged($node);
			if ($ifchanged !== null) {
				return $ifchanged;
			}
		}

		if ($node instanceof Foreach_) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof Expression && $this->isTryStash($node->expr)) {
			return NodeVisitor::REMOVE_NODE;
		}

		if ($node instanceof Variable && $node->name === self::SWITCH_TEMP) {
			return new Variable(self::SWITCH_RENAME);
		}

		if ($node instanceof Variable && $node->name === self::EXCEPTION_TEMP) {
			return new Variable(self::EXCEPTION_RENAME);
		}

		if (
			$node instanceof ArrayDimFetch
			&& $this->isVariableNamed($node->var, self::TAG_IF_TEMP)
			&& $node->dim instanceof Int_
		) {
			return new Variable('latteTagIf' . $node->dim->value);
		}

		return null;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function normalizeStmts(array $stmts): array
	{
		$result = [];
		$count = count($stmts);
		$i = 0;

		while ($i < $count) {
			$stmt = $stmts[$i];
			$next = $stmts[$i + 1] ?? null;

			if ($this->isNoopObStart($stmt) && $next instanceof TryCatch) {
				$tryShell = $this->rebuildTryShell($next);
				if ($tryShell !== null) {
					$result[] = $tryShell;
					$i += 2;

					continue;
				}

				$varName = $this->ifCaptureVarName($next);
				$after = $stmts[$i + 2] ?? null;
				if ($varName !== null && $after instanceof If_ && $this->isIfEchoingVar($after, $varName)) {
					$result[] = new If_($after->cond, ['stmts' => $next->stmts]);
					$i += 3;

					continue;
				}
			}

			$result[] = $stmt;
			$i++;
		}

		return $result;
	}

	private function isTryStash(Expr $expr): bool
	{
		if (!$expr instanceof Assign || !$expr->var instanceof ArrayDimFetch) {
			return false;
		}

		if (!$this->isVariableNamed($expr->var->var, self::TRY_TEMP)) {
			return false;
		}

		if (!$expr->expr instanceof Array_ || count($expr->expr->items) !== 1) {
			return false;
		}

		$item = $expr->expr->items[0];

		return $item->value instanceof Coalesce
			&& $this->isVariableNamed($item->value->left, self::ITERATOR_TEMP);
	}

	private function isNoopObStart(Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof FuncCall) {
			return false;
		}

		if (!$this->isFuncCallNamed($stmt->expr, 'ob_start') || count($stmt->expr->args) !== 1) {
			return false;
		}

		$arg = $stmt->expr->args[0];
		if (!$arg instanceof Node\Arg || !$arg->value instanceof Node\Expr\Closure) {
			return false;
		}

		$closure = $arg->value;

		return $closure->params === [] && $closure->uses === [] && $closure->stmts === [];
	}

	private function rebuildTryShell(TryCatch $node): ?TryCatch
	{
		if (count($node->catches) !== 1) {
			return null;
		}

		$catch = $node->catches[0];
		if (count($catch->types) !== 1 || $catch->types[0]->toString() !== 'Throwable') {
			return null;
		}

		if (!$this->isTryRestoreFinally($node->finally)) {
			return null;
		}

		$catchStmts = $catch->stmts;
		$lastIndex = count($catchStmts) - 1;
		if ($lastIndex < 1) {
			return null;
		}

		if (
			!$this->isBareFuncCall($catchStmts[0], 'ob_end_clean')
			|| !$this->isBareFuncCall($catchStmts[$lastIndex], 'ob_start')
		) {
			return null;
		}

		$middleStmts = array_slice($catchStmts, 1, -1);
		$newCatch = new Catch_($catch->types, $catch->var, $middleStmts);

		return new TryCatch($node->stmts, [$newCatch], null);
	}

	private function isTryRestoreFinally(?Finally_ $finally): bool
	{
		if ($finally === null || count($finally->stmts) !== 2) {
			return false;
		}

		$echoStmt = $finally->stmts[0];
		if (
			!$echoStmt instanceof Echo_
			|| count($echoStmt->exprs) !== 1
			|| !$this->isBareFuncCallExpr($echoStmt->exprs[0], 'ob_get_clean')
		) {
			return false;
		}

		$restoreStmt = $finally->stmts[1];
		if (!$restoreStmt instanceof Expression || !$restoreStmt->expr instanceof Assign) {
			return false;
		}

		if (
			!$this->isVariableNamed($restoreStmt->expr->var, 'iterator')
			|| !$restoreStmt->expr->expr instanceof Assign
		) {
			return false;
		}

		$inner = $restoreStmt->expr->expr;
		if (!$this->isVariableNamed($inner->var, self::ITERATOR_TEMP) || !$inner->expr instanceof ArrayDimFetch) {
			return false;
		}

		return $inner->expr->var instanceof ArrayDimFetch && $this->isVariableNamed(
			$inner->expr->var->var,
			self::TRY_TEMP,
		);
	}

	private function ifCaptureVarName(TryCatch $node): ?string
	{
		if (count($node->catches) !== 0 || $node->finally === null || count($node->finally->stmts) !== 1) {
			return null;
		}

		$stmt = $node->finally->stmts[0];
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return null;
		}

		$var = $stmt->expr->var;
		if (!$var instanceof Variable || !is_string($var->name) || strpos($var->name, self::IF_CAPTURE_PREFIX) !== 0) {
			return null;
		}

		if (!$this->isBareFuncCallExpr($stmt->expr->expr, 'ob_get_clean')) {
			return null;
		}

		return $var->name;
	}

	private function isIfEchoingVar(If_ $node, string $varName): bool
	{
		if ($node->elseifs !== [] || $node->else !== null || count($node->stmts) !== 1) {
			return false;
		}

		$stmt = $node->stmts[0];
		if (!$stmt instanceof Echo_ || count($stmt->exprs) !== 1) {
			return false;
		}

		$expr = $stmt->exprs[0];

		return $expr instanceof Variable && $expr->name === $varName;
	}

	private function rebuildIfchanged(If_ $node): ?If_
	{
		if (!$node->cond instanceof NotIdentical) {
			return null;
		}

		$cond = $node->cond;
		if (!$cond->left instanceof Coalesce || !$cond->left->left instanceof ArrayDimFetch) {
			return null;
		}

		$locFetch = $cond->left->left;
		if (!$this->isVariableNamed($locFetch->var, self::LOC_TEMP) || !$locFetch->dim instanceof Int_) {
			return null;
		}

		if (!$cond->right instanceof Assign) {
			return null;
		}

		$compareAssign = $cond->right;
		$compareVar = $compareAssign->var;
		if (!$compareVar instanceof Variable || !is_string($compareVar->name)) {
			return null;
		}

		$tmpVarName = $compareVar->name;

		if (count($node->stmts) < 1) {
			return null;
		}

		$writeBack = $node->stmts[0];
		if (!$writeBack instanceof Expression || !$writeBack->expr instanceof Assign) {
			return null;
		}

		$writeAssign = $writeBack->expr;
		if (
			!$writeAssign->var instanceof ArrayDimFetch
			|| !$this->isVariableNamed($writeAssign->var->var, self::LOC_TEMP)
		) {
			return null;
		}

		if (!$this->isVariableNamed($writeAssign->expr, $tmpVarName)) {
			return null;
		}

		$newVarName = 'latteIfchanged' . $locFetch->dim->value;
		$captureAssign = new Expression(new Assign(new Variable($newVarName), $compareAssign->expr));

		$bodyStmts = array_slice($node->stmts, 1);
		array_unshift($bodyStmts, $captureAssign);

		return new If_(new ConstFetch(new Name('true')), ['stmts' => $bodyStmts]);
	}

	private function isBareFuncCall(Stmt $stmt, string $name): bool
	{
		return $stmt instanceof Expression && $this->isBareFuncCallExpr($stmt->expr, $name);
	}

	private function isBareFuncCallExpr(Expr $expr, string $name): bool
	{
		return $expr instanceof FuncCall && $this->isFuncCallNamed($expr, $name) && count($expr->args) === 0;
	}

	private function isFuncCallNamed(FuncCall $node, string $name): bool
	{
		return $node->name instanceof Name && $node->name->toString() === $name;
	}

	private function isVariableNamed(Node $node, string $name): bool
	{
		return $node instanceof Variable && $node->name === $name;
	}

}
