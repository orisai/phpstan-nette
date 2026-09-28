<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\GlobalPropertyFetchMatcher;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use function count;
use function is_string;

final class UiMacroEliminator extends EliminatorVisitor
{

	private const HELPERS_CLASS = Helpers::class;

	private const RENDERABLE_CLASS = 'Nette\Application\UI\Renderable';

	public function describePattern(): string
	{
		return '{control}/{control $obj:part} $_tmp = getComponent(...)/is_object($obj) ? ... shell + '
			. 'instanceof Renderable redrawControl guard + render*(...) call -> Helpers::component(<name expr>); '
			. 'render*(...) call args kept as analyzed statements; {link}/{plink}/n:href uiControl|uiPresenter->link(...) '
			. '(already unwrapped of its escapeHtmlAttr(...) by EscapingEliminator) -> Helpers::uiLink(<dest>, [<args>]); '
			. '{ifCurrent dest[, args]}/{ifCurrent} if ($this->global->uiPresenter->isLinkCurrent(<dest>[, <args>])'
			. '|getLastCreatedRequestFlag("current")) {body} -> if (Helpers::uiIsLinkCurrent(<dest>|null)) {body}, '
			. '<args> (if present) kept analyzed via a preceding Helpers::analyzed(<args>) statement';
	}

	/**
	 * @return Node|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof ClassMethod && $node->stmts !== null) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof If_) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof Foreach_) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof MethodCall && $this->isUiLinkCall($node)) {
			return new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('uiLink'), $node->args);
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
			$shell = $this->tryMatchControlShell($stmts, $i);
			if ($shell !== null) {
				foreach ($shell as $shellStmt) {
					$result[] = $shellStmt;
				}

				$i += 3;

				continue;
			}

			$ifCurrentShell = $this->tryMatchIfCurrentShell($stmts[$i]);
			if ($ifCurrentShell !== null) {
				foreach ($ifCurrentShell as $shellStmt) {
					$result[] = $shellStmt;
				}

				$i++;

				continue;
			}

			$result[] = $stmts[$i];
			$i++;
		}

		return $result;
	}

	/**
	 * @return array<Stmt>|null
	 */
	private function tryMatchIfCurrentShell(Stmt $stmt): ?array
	{
		if (!$stmt instanceof If_) {
			return null;
		}

		$match = $this->matchIfCurrentCond($stmt->cond);
		if ($match === null) {
			return null;
		}

		[$destExpr, $argsExpr] = $match;

		$result = [];
		if ($argsExpr !== null) {
			$result[] = new Expression(
				new StaticCall(
					new FullyQualified(self::HELPERS_CLASS),
					new Identifier('analyzed'),
					[new Arg($argsExpr)],
				),
			);
		}

		$stmt->cond = new StaticCall(
			new FullyQualified(self::HELPERS_CLASS),
			new Identifier('uiIsLinkCurrent'),
			[new Arg($destExpr)],
		);
		$result[] = $stmt;

		return $result;
	}

	/**
	 * @return array{Expr, Expr|null}|null
	 */
	private function matchIfCurrentCond(Expr $cond): ?array
	{
		if (!$cond instanceof MethodCall || !GlobalPropertyFetchMatcher::matches($cond->var, 'uiPresenter')) {
			return null;
		}

		if (!$cond->name instanceof Identifier) {
			return null;
		}

		$methodName = $cond->name->toString();

		if ($methodName === 'isLinkCurrent' && count($cond->args) >= 1 && count($cond->args) <= 2) {
			$destArg = $cond->args[0];
			if (!$destArg instanceof Arg) {
				return null;
			}

			$argsArg = $cond->args[1] ?? null;
			$argsExpr = $argsArg instanceof Arg ? $argsArg->value : null;

			return [$destArg->value, $argsExpr];
		}

		if (
			$methodName === 'getLastCreatedRequestFlag'
			&& count($cond->args) === 1
			&& $cond->args[0] instanceof Arg
			&& $cond->args[0]->value instanceof String_
			&& $cond->args[0]->value->value === 'current'
		) {
			return [new ConstFetch(new Name('null')), null];
		}

		return null;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>|null
	 */
	private function tryMatchControlShell(array $stmts, int $i): ?array
	{
		$match = $this->matchStaticGetComponent($stmts[$i] ?? null) ?? $this->matchDynamicGetComponent(
			$stmts[$i] ?? null,
		);
		if ($match === null) {
			return null;
		}

		[$tmpVarName, $nameExpr] = $match;

		$redrawIf = $stmts[$i + 1] ?? null;
		if (!$redrawIf instanceof If_ || !$this->matchRedrawIf($redrawIf, $tmpVarName)) {
			return null;
		}

		$renderCall = $this->matchRenderCall($stmts[$i + 2] ?? null, $tmpVarName);
		if ($renderCall === null) {
			return null;
		}

		return $this->buildComponentStmts($nameExpr, $renderCall);
	}

	/**
	 * @return array{string, Expr}|null
	 */
	private function matchStaticGetComponent(?Stmt $stmt): ?array
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return null;
		}

		$assign = $stmt->expr;
		if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
			return null;
		}

		$call = $assign->expr;
		if (!$call instanceof MethodCall || !GlobalPropertyFetchMatcher::matches($call->var, 'uiControl')) {
			return null;
		}

		if (!$call->name instanceof Identifier || $call->name->toString() !== 'getComponent') {
			return null;
		}

		if (count($call->args) !== 1 || !$call->args[0] instanceof Arg) {
			return null;
		}

		return [$assign->var->name, $call->args[0]->value];
	}

	/**
	 * @return array{string, Expr}|null
	 */
	private function matchDynamicGetComponent(?Stmt $stmt): ?array
	{
		if (!$stmt instanceof If_ || $stmt->elseifs !== [] || $stmt->else === null) {
			return null;
		}

		if (count($stmt->stmts) !== 1 || count($stmt->else->stmts) !== 1) {
			return null;
		}

		$ifAssign = $this->extractAssign($stmt->stmts[0]);
		$elseAssign = $this->extractAssign($stmt->else->stmts[0]);
		if ($ifAssign === null || $elseAssign === null) {
			return null;
		}

		if (!$ifAssign->var instanceof Variable || !is_string($ifAssign->var->name)) {
			return null;
		}

		if (!$this->isVariableNamed($elseAssign->var, $ifAssign->var->name)) {
			return null;
		}

		if (
			!$elseAssign->expr instanceof MethodCall
			|| !GlobalPropertyFetchMatcher::matches($elseAssign->expr->var, 'uiControl')
		) {
			return null;
		}

		if (!$elseAssign->expr->name instanceof Identifier || $elseAssign->expr->name->toString() !== 'getComponent') {
			return null;
		}

		if (
			!$stmt->cond instanceof FuncCall
			|| !$this->isFuncCallNamed($stmt->cond, 'is_object')
			|| count($stmt->cond->args) !== 1
		) {
			return null;
		}

		$arg = $stmt->cond->args[0];

		return $arg instanceof Arg ? [$ifAssign->var->name, $arg->value] : null;
	}

	private function extractAssign(Stmt $stmt): ?Assign
	{
		return $stmt instanceof Expression && $stmt->expr instanceof Assign ? $stmt->expr : null;
	}

	private function matchRedrawIf(If_ $node, string $tmpVarName): bool
	{
		if ($node->elseifs !== [] || $node->else !== null || count($node->stmts) !== 1) {
			return false;
		}

		if (!$node->cond instanceof Instanceof_ || !$this->isVariableNamed($node->cond->expr, $tmpVarName)) {
			return false;
		}

		if (!$node->cond->class instanceof Name || $node->cond->class->toString() !== self::RENDERABLE_CLASS) {
			return false;
		}

		$stmt = $node->stmts[0];

		return $stmt instanceof Expression
			&& $stmt->expr instanceof MethodCall
			&& $this->isVariableNamed($stmt->expr->var, $tmpVarName)
			&& $stmt->expr->name instanceof Identifier
			&& $stmt->expr->name->toString() === 'redrawControl';
	}

	private function matchRenderCall(?Stmt $stmt, string $tmpVarName): ?MethodCall
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return null;
		}

		return $this->isVariableNamed($stmt->expr->var, $tmpVarName) ? $stmt->expr : null;
	}

	/**
	 * @return array<Stmt>
	 */
	private function buildComponentStmts(Expr $nameExpr, MethodCall $renderCall): array
	{
		$result = [
			new Expression(
				new StaticCall(
					new FullyQualified(self::HELPERS_CLASS),
					new Identifier('component'),
					[new Arg($nameExpr)],
				),
			),
		];

		foreach ($renderCall->args as $arg) {
			if ($arg instanceof Arg) {
				$result[] = new Expression($arg->value);
			}
		}

		return $result;
	}

	private function isUiLinkCall(MethodCall $node): bool
	{
		if (!$node->name instanceof Identifier || $node->name->toString() !== 'link') {
			return false;
		}

		return GlobalPropertyFetchMatcher::matches($node->var, 'uiControl') || GlobalPropertyFetchMatcher::matches(
			$node->var,
			'uiPresenter',
		);
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
