<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\GlobalPropertyFetchMatcher;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BooleanNot;
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
use function array_slice;
use function count;
use function is_string;

final class UiMacroEliminator extends EliminatorVisitor
{

	// {control $obj} resolves the operand to a component: Latte 2 with an if/else on is_object(),
	// Latte 3 with a single if (!is_object($ʟ_tmp = ...)) re-assigning the temp.
	public const ROLE_DYNAMIC_COMPONENT = 'dynamicComponent';

	public const SHAPE_IS_OBJECT_IF_ELSE = 'isObjectIfElse';

	public const SHAPE_NOT_IS_OBJECT_ASSIGN = 'notIsObjectAssign';

	private const HELPERS_CLASS = Helpers::class;

	private const RENDERABLE_CLASS = 'Nette\Application\UI\Renderable';

	public function describePattern(): string
	{
		return '{control}/{control $obj:part} $_tmp = getComponent(...)/<dynamicComponent> shell + '
			. 'instanceof Renderable redrawControl guard + render*(...) call -> Helpers::component(<name expr>); '
			. 'render*(...) call args kept as analyzed statements; {link}/{plink}/n:href uiControl|uiPresenter->link(...) '
			. '(already unwrapped of its escapeHtmlAttr(...) by EscapingEliminator) -> Helpers::uiLink(<dest>, [<args>]); '
			. '{ifCurrent dest[, args]}/{ifCurrent} if ($this->global->uiPresenter->isLinkCurrent(<dest>[, <args>])'
			. '|getLastCreatedRequestFlag("current")) {body} -> if (Helpers::uiIsLinkCurrent(<dest>|null)) {body}, '
			. '<args> (if present) kept analyzed via a preceding Helpers::analyzed(<args>) statement: '
			. $this->patterns()->describe();
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

		[$destExpr, $argExprs] = $match;

		$result = [];
		if ($argExprs !== []) {
			$args = [];
			foreach ($argExprs as $argExpr) {
				$args[] = new Arg($argExpr);
			}

			$result[] = new Expression(
				new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('analyzed'), $args),
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
	 * @return array{Expr, list<Expr>}|null
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

		if ($methodName === 'isLinkCurrent' && count($cond->args) >= 1) {
			$destArg = $cond->args[0];
			if (!$destArg instanceof Arg) {
				return null;
			}

			$argExprs = [];
			foreach (array_slice($cond->args, 1) as $arg) {
				if (!$arg instanceof Arg) {
					return null;
				}

				$argExprs[] = $arg->value;
			}

			return [$destArg->value, $argExprs];
		}

		if (
			$methodName === 'getLastCreatedRequestFlag'
			&& count($cond->args) === 1
			&& $cond->args[0] instanceof Arg
			&& $cond->args[0]->value instanceof String_
			&& $cond->args[0]->value->value === 'current'
		) {
			return [new ConstFetch(new Name('null')), []];
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

		$nameExpr = $this->matchGetComponentCall($assign->expr);

		return $nameExpr === null ? null : [$assign->var->name, $nameExpr];
	}

	/**
	 * @return array{string, Expr}|null
	 */
	private function matchDynamicGetComponent(?Stmt $stmt): ?array
	{
		if (!$stmt instanceof If_ || $stmt->elseifs !== []) {
			return null;
		}

		if ($this->patterns()->hasName(self::ROLE_DYNAMIC_COMPONENT, self::SHAPE_IS_OBJECT_IF_ELSE)) {
			return $this->matchIsObjectIfElse($stmt);
		}

		if ($this->patterns()->hasName(self::ROLE_DYNAMIC_COMPONENT, self::SHAPE_NOT_IS_OBJECT_ASSIGN)) {
			return $this->matchNotIsObjectAssign($stmt);
		}

		return null;
	}

	/**
	 * @return array{string, Expr}|null
	 */
	private function matchIsObjectIfElse(If_ $stmt): ?array
	{
		if ($stmt->else === null || count($stmt->stmts) !== 1 || count($stmt->else->stmts) !== 1) {
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

		if ($this->matchGetComponentCall($elseAssign->expr) === null) {
			return null;
		}

		$operand = $this->matchIsObjectOperand($stmt->cond);

		return $operand === null ? null : [$ifAssign->var->name, $operand];
	}

	/**
	 * @return array{string, Expr}|null
	 */
	private function matchNotIsObjectAssign(If_ $stmt): ?array
	{
		if ($stmt->else !== null || count($stmt->stmts) !== 1 || !$stmt->cond instanceof BooleanNot) {
			return null;
		}

		$operand = $this->matchIsObjectOperand($stmt->cond->expr);
		if (!$operand instanceof Assign || !$operand->var instanceof Variable || !is_string($operand->var->name)) {
			return null;
		}

		$tmpVarName = $operand->var->name;
		$assign = $this->extractAssign($stmt->stmts[0]);
		if ($assign === null || !$this->isVariableNamed($assign->var, $tmpVarName)) {
			return null;
		}

		$lookup = $this->matchGetComponentCall($assign->expr);

		return $lookup !== null && $this->isVariableNamed($lookup, $tmpVarName) ? [$tmpVarName, $operand->expr] : null;
	}

	private function matchIsObjectOperand(Expr $cond): ?Expr
	{
		if (!$cond instanceof FuncCall || !$this->isFuncCallNamed($cond, 'is_object') || count($cond->args) !== 1) {
			return null;
		}

		$arg = $cond->args[0];

		return $arg instanceof Arg ? $arg->value : null;
	}

	private function matchGetComponentCall(Expr $expr): ?Expr
	{
		if (!$expr instanceof MethodCall || !GlobalPropertyFetchMatcher::matches($expr->var, 'uiControl')) {
			return null;
		}

		if (!$expr->name instanceof Identifier || $expr->name->toString() !== 'getComponent') {
			return null;
		}

		if (count($expr->args) !== 1 || !$expr->args[0] instanceof Arg) {
			return null;
		}

		return $expr->args[0]->value;
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
