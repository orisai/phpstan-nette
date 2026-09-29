<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeVisitor;
use function count;
use function in_array;
use function is_string;

final class CaptureEliminator extends EliminatorVisitor
{

	private const TMP_TEMP = "\u{29F}_tmp";

	private const FILTER_INFO_TEMP = "\u{29F}_fi";

	private const FILTER_INFO_CLASS = 'Latte\Runtime\FilterInfo';

	private const HTML_CLASS_NAMES = ['Latte\Runtime\Html', 'LR\Html'];

	private const HELPERS_CLASS = Helpers::class;

	// {spaceless} as an ob_start(handler, 4096)/try/finally ob_end_flush() shell: the handler callables.
	public const ROLE_SPACELESS_HANDLER = 'spacelessHandler';

	// {spaceless} as a start()/try/finally end() pair of static calls (Latte 3.1).
	public const ROLE_SPACELESS_START = 'spacelessStart';

	public const ROLE_SPACELESS_END = 'spacelessEnd';

	// The `if ($ʟ_fi->contentType === 'html' && $v !== '') $v = new Html($v);` guard Latte 3.1 appends
	// to a filtered {capture}: the compared FilterInfo property.
	public const ROLE_FILTERED_CAPTURE_HTML_GUARD = 'filteredCaptureHtmlGuard';

	public function describePattern(): string
	{
		$patterns = $this->patterns();

		return '{capture $v}/{translate} ob_start(noop)/try/finally $ʟ_tmp = ob_get_length() ? new Html(ob_get_clean()) '
			. ': ob_get_clean() (or bare ob_get_clean() for {translate}) shell -> body statements + $ʟ_tmp = Helpers::capturedString(); '
			. 'further collapsed to $v = Helpers::capturedString() when immediately followed by the unfiltered '
			. '$ʟ_fi = new FilterInfo(...); $v = $ʟ_tmp; tail (filtered-capture/translate filterContent tails referencing $ʟ_fi '
			. 'are left in place); {spaceless} '
			. ($patterns->has(
				self::ROLE_SPACELESS_HANDLER,
			) ? 'ob_start(handler, 4096)/try/finally ob_end_flush()' : 'start()/try/finally end()')
			. ' shell -> body statements only'
			. ($patterns->has(
				self::ROLE_FILTERED_CAPTURE_HTML_GUARD,
			) ? '; filtered-capture Html re-wrap guard dropped' : '')
			. ': ' . $patterns->describe();
	}

	/**
	 * @return int|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof ClassMethod && $node->stmts !== null) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof If_) {
			if ($this->isFilteredCaptureHtmlGuard($node)) {
				return NodeVisitor::REMOVE_NODE;
			}

			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof Foreach_) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		return null;
	}

	private function isFilteredCaptureHtmlGuard(If_ $node): bool
	{
		$patterns = $this->patterns();
		if (!$patterns->has(self::ROLE_FILTERED_CAPTURE_HTML_GUARD) || $node->elseifs !== [] || $node->else !== null) {
			return false;
		}

		$cond = $node->cond;
		if (
			!$cond instanceof BooleanAnd
			|| !$cond->left instanceof Identical
			|| !$cond->right instanceof NotIdentical
		) {
			return false;
		}

		$contentType = $cond->left->left;
		if (
			!$contentType instanceof PropertyFetch
			|| !$this->isVariableNamed($contentType->var, self::FILTER_INFO_TEMP)
			|| !$contentType->name instanceof Identifier
			|| $contentType->name->toString() !== $patterns->name(self::ROLE_FILTERED_CAPTURE_HTML_GUARD)
			|| !$cond->left->right instanceof String_
		) {
			return false;
		}

		$captured = $cond->right->left;
		if (
			!$captured instanceof Variable
			|| !is_string($captured->name)
			|| !$cond->right->right instanceof String_
			|| $cond->right->right->value !== ''
			|| count($node->stmts) !== 1
		) {
			return false;
		}

		$stmt = $node->stmts[0];
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return false;
		}

		$rewrap = $stmt->expr->expr;

		return $stmt->expr->var instanceof Variable
			&& $stmt->expr->var->name === $captured->name
			&& $rewrap instanceof New_
			&& $rewrap->class instanceof Name
			&& in_array($rewrap->class->toString(), self::HTML_CLASS_NAMES, true)
			&& count($rewrap->args) === 1
			&& $rewrap->args[0] instanceof Arg
			&& $this->isVariableNamed($rewrap->args[0]->value, $captured->name);
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

			if ($next instanceof TryCatch) {
				if ($this->isSpacelessShell($stmt, $next)) {
					foreach ($this->normalizeStmts($next->stmts) as $bodyStmt) {
						$result[] = $bodyStmt;
					}

					$i += 2;

					continue;
				}

				$capturedBody = $this->matchObCaptureShell($stmt, $next);
				if ($capturedBody !== null) {
					foreach ($this->normalizeStmts($capturedBody) as $bodyStmt) {
						$result[] = $bodyStmt;
					}

					$collapsedTail = $this->collapseUnfilteredTail($stmts, $i + 2);
					if ($collapsedTail !== null) {
						$result[] = $collapsedTail;
						$i += 4;

						continue;
					}

					$result[] = $this->tmpAssignHelperCall();
					$i += 2;

					continue;
				}
			}

			$result[] = $stmt;
			$i++;
		}

		return $result;
	}

	/**
	 * @return array<Stmt>|null
	 */
	private function matchObCaptureShell(Stmt $obStart, TryCatch $tryCatch): ?array
	{
		if (
			!NoopObStart::matches($obStart, $this->patterns())
			|| count($tryCatch->catches) !== 0
			|| $tryCatch->finally === null
		) {
			return null;
		}

		return $this->isCaptureFinally($tryCatch->finally) ? $tryCatch->stmts : null;
	}

	private function isCaptureFinally(Finally_ $finally): bool
	{
		if (count($finally->stmts) !== 1) {
			return false;
		}

		$stmt = $finally->stmts[0];
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return false;
		}

		if (!$this->isVariableNamed($stmt->expr->var, self::TMP_TEMP)) {
			return false;
		}

		return $this->isHtmlAwareObGet($stmt->expr->expr) || $this->isBareFuncCallExpr(
			$stmt->expr->expr,
			'ob_get_clean',
		);
	}

	private function isHtmlAwareObGet(Expr $expr): bool
	{
		if (!$expr instanceof Ternary || $expr->if === null) {
			return false;
		}

		if (!$this->isBareFuncCallExpr($expr->cond, 'ob_get_length')) {
			return false;
		}

		if (!$expr->if instanceof New_ || !$expr->if->class instanceof Name) {
			return false;
		}

		if (!in_array($expr->if->class->toString(), self::HTML_CLASS_NAMES, true)) {
			return false;
		}

		if (count($expr->if->args) !== 1 || !$expr->if->args[0] instanceof Arg) {
			return false;
		}

		return $this->isBareFuncCallExpr($expr->if->args[0]->value, 'ob_get_clean')
			&& $this->isBareFuncCallExpr($expr->else, 'ob_get_clean');
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	private function collapseUnfilteredTail(array $stmts, int $index): ?Expression
	{
		$fiStmt = $stmts[$index] ?? null;
		$assignStmt = $stmts[$index + 1] ?? null;

		if (
			!$this->isFilterInfoAssign($fiStmt)
			|| !$assignStmt instanceof Expression
			|| !$assignStmt->expr instanceof Assign
		) {
			return null;
		}

		$target = $assignStmt->expr->var;
		$source = $assignStmt->expr->expr;

		if (!$target instanceof Variable || !$this->isVariableNamed($source, self::TMP_TEMP)) {
			return null;
		}

		return new Expression(new Assign($target, $this->capturedStringCall()));
	}

	private function isFilterInfoAssign(?Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return false;
		}

		if (!$this->isVariableNamed($stmt->expr->var, self::FILTER_INFO_TEMP)) {
			return false;
		}

		$new = $stmt->expr->expr;

		return $new instanceof New_ && $new->class instanceof Name && $new->class->toString() === self::FILTER_INFO_CLASS;
	}

	private function tmpAssignHelperCall(): Expression
	{
		return new Expression(new Assign(new Variable(self::TMP_TEMP), $this->capturedStringCall()));
	}

	private function capturedStringCall(): StaticCall
	{
		return new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('capturedString'), []);
	}

	private function isSpacelessShell(Stmt $open, TryCatch $tryCatch): bool
	{
		if (count($tryCatch->catches) !== 0 || $tryCatch->finally === null || count($tryCatch->finally->stmts) !== 1) {
			return false;
		}

		$close = $tryCatch->finally->stmts[0];
		if ($this->isSpacelessObStart($open)) {
			return $this->isBareFuncCall($close, 'ob_end_flush');
		}

		return $this->isRoleStaticCall(self::ROLE_SPACELESS_START, $open)
			&& $this->isRoleStaticCall(self::ROLE_SPACELESS_END, $close);
	}

	private function isSpacelessObStart(Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof FuncCall) {
			return false;
		}

		if (!$this->isFuncCallNamed($stmt->expr, 'ob_start') || count($stmt->expr->args) !== 2) {
			return false;
		}

		[$handlerArg, $sizeArg] = $stmt->expr->args;
		if (!$handlerArg instanceof Arg || !$handlerArg->value instanceof String_) {
			return false;
		}

		if (!$sizeArg instanceof Arg || !$sizeArg->value instanceof Int_ || $sizeArg->value->value !== 4096) {
			return false;
		}

		return $this->patterns()->hasName(self::ROLE_SPACELESS_HANDLER, $handlerArg->value->value);
	}

	private function isRoleStaticCall(string $role, Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof StaticCall) {
			return false;
		}

		$call = $stmt->expr;
		if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
			return false;
		}

		return $this->patterns()->isStaticCall($role, $call->class->toString(), $call->name->toString());
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
