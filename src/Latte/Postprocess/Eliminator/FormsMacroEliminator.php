<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\GlobalPropertyFetchMatcher;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitor;
use function count;
use function is_string;

final class FormsMacroEliminator extends EliminatorVisitor
{

	private const HELPERS_CLASS = Helpers::class;

	private const FORMS_RUNTIME_CLASS = 'Nette\Bridges\FormsLatte\Runtime';

	private const INPUT_TEMP = "\u{29F}_input";

	private const INPUT_RENAME = 'latteInput';

	private const LABEL_TEMP = "\u{29F}_label";

	private const LABEL_RENAME = 'latteLabel';

	public function describePattern(): string
	{
		return '{form x}/{form $var} $form = formsStack[] = uiControl["x"] (or is_object(...) ? ... : uiControl[...] '
			. 'for an object form) + initializeForm($form) + echo renderFormBegin(...) shell -> $form = Helpers::form(\'x\') '
			. '/ Helpers::formObject($var); interleaved literal HTML echo (attribute-form <form n:name> case) kept in place; '
			. 'echo renderFormEnd(array_pop(...), ...) dropped, $form stays bound; {formContainer c} push shell -> '
			. '$formContainer = Helpers::formContainer(\'c\'); {/formContainer} pop (array_pop + re-derive) dropped '
			. '(unprovable parent restore); {input}/{label}/{inputError}/n:name end($this->global->formsStack)["x"] base '
			. '-> Helpers::formField(\'x\'), trailing ->getControl()/->getLabel()/->getError()/->getControlPart()->attributes() '
			. 'chain kept; $ʟ_input/$ʟ_label temps renamed to $latteInput/$latteLabel';
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

			$labelIf = $this->rebuildLabelIf($node);
			if ($labelIf !== null) {
				return $labelIf;
			}
		}

		if ($node instanceof Foreach_) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof Expression) {
			$containerPush = $this->rebuildContainerPush($node->expr);
			if ($containerPush !== null) {
				return new Expression($containerPush);
			}

			$inputAssign = $this->rebuildInputAssign($node->expr);
			if ($inputAssign !== null) {
				return new Expression($inputAssign);
			}

			if ($this->isContainerPopDrop($node->expr) || $this->isContainerReassignDrop($node->expr)) {
				return NodeVisitor::REMOVE_NODE;
			}
		}

		if ($node instanceof Echo_) {
			if ($this->matchRenderFormEndEcho($node)) {
				return NodeVisitor::REMOVE_NODE;
			}

			$fieldEcho = $this->rebuildFieldEcho($node);
			if ($fieldEcho !== null) {
				return $fieldEcho;
			}
		}

		if ($node instanceof Variable && $node->name === self::INPUT_TEMP) {
			return new Variable(self::INPUT_RENAME);
		}

		if ($node instanceof Variable && $node->name === self::LABEL_TEMP) {
			return new Variable(self::LABEL_RENAME);
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
			$collapsed = $this->tryMatchFormBeginShell($stmts, $i);
			if ($collapsed !== null) {
				[$consumed, $replacement] = $collapsed;
				foreach ($replacement as $replacementStmt) {
					$result[] = $replacementStmt;
				}

				$i += $consumed;

				continue;
			}

			$result[] = $stmts[$i];
			$i++;
		}

		return $result;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array{int, array<Stmt>}|null
	 */
	private function tryMatchFormBeginShell(array $stmts, int $i): ?array
	{
		$push = $this->matchFormPush($stmts[$i] ?? null);
		if ($push === null) {
			return null;
		}

		[$formVarName, $helperMethod, $sourceExpr] = $push;

		if (!$this->matchInitializeForm($stmts[$i + 1] ?? null, $formVarName)) {
			return null;
		}

		$helperCall = new Expression(new Assign(
			new Variable($formVarName),
			new StaticCall(
				new FullyQualified(self::HELPERS_CLASS),
				new Identifier($helperMethod),
				[new Arg($sourceExpr)],
			),
		));

		if ($this->matchRenderFormBeginEcho($stmts[$i + 2] ?? null)) {
			return [3, [$helperCall]];
		}

		$kept = $stmts[$i + 2] ?? null;
		if ($kept !== null && $this->matchRenderFormBeginEcho($stmts[$i + 3] ?? null)) {
			return [4, [$helperCall, $kept]];
		}

		return null;
	}

	/**
	 * @return array{string, string, Expr}|null
	 */
	private function matchFormPush(?Stmt $stmt): ?array
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return null;
		}

		$outer = $stmt->expr;
		if (!$outer->var instanceof Variable || !is_string($outer->var->name)) {
			return null;
		}

		if (!$outer->expr instanceof Assign || !$this->isFormsStackPush($outer->expr->var)) {
			return null;
		}

		$source = $outer->expr->expr;

		if (
			$source instanceof ArrayDimFetch
			&& $source->dim !== null
			&& GlobalPropertyFetchMatcher::matches($source->var, 'uiControl')
		) {
			return [$outer->var->name, 'form', $source->dim];
		}

		$dynamicSource = $this->matchDynamicFormSource($source);
		if ($dynamicSource !== null) {
			return [$outer->var->name, 'formObject', $dynamicSource];
		}

		return null;
	}

	private function matchDynamicFormSource(Expr $source): ?Expr
	{
		if (!$source instanceof Ternary || $source->if === null) {
			return null;
		}

		if (
			!$source->cond instanceof FuncCall
			|| !$this->isFuncCallNamed($source->cond, 'is_object')
			|| count($source->cond->args) !== 1
		) {
			return null;
		}

		$condArg = $source->cond->args[0];
		if (!$condArg instanceof Arg || !$condArg->value instanceof Assign) {
			return null;
		}

		$tmpAssign = $condArg->value;
		if (!$tmpAssign->var instanceof Variable || !is_string($tmpAssign->var->name)) {
			return null;
		}

		$tmpName = $tmpAssign->var->name;

		if (!$this->isVariableNamed($source->if, $tmpName)) {
			return null;
		}

		if (
			!$source->else instanceof ArrayDimFetch
			|| !GlobalPropertyFetchMatcher::matches($source->else->var, 'uiControl')
		) {
			return null;
		}

		if (!$this->isVariableNamed($source->else->dim, $tmpName)) {
			return null;
		}

		return $tmpAssign->expr;
	}

	private function isFormsStackPush(Expr $expr): bool
	{
		return $expr instanceof ArrayDimFetch && $expr->dim === null && GlobalPropertyFetchMatcher::matches(
			$expr->var,
			'formsStack',
		);
	}

	private function matchInitializeForm(?Stmt $stmt, string $formVarName): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof StaticCall) {
			return false;
		}

		$call = $stmt->expr;
		if (!$call->class instanceof Name || $call->class->toString() !== self::FORMS_RUNTIME_CLASS) {
			return false;
		}

		if (!$call->name instanceof Identifier || $call->name->toString() !== 'initializeForm') {
			return false;
		}

		if (count($call->args) !== 1 || !$call->args[0] instanceof Arg) {
			return false;
		}

		return $this->isVariableNamed($call->args[0]->value, $formVarName);
	}

	private function matchRenderFormBeginEcho(?Stmt $stmt): bool
	{
		if (!$stmt instanceof Echo_ || count($stmt->exprs) !== 1) {
			return false;
		}

		$expr = $stmt->exprs[0];

		return $expr instanceof StaticCall
			&& $expr->class instanceof Name
			&& $expr->class->toString() === self::FORMS_RUNTIME_CLASS
			&& $expr->name instanceof Identifier
			&& $expr->name->toString() === 'renderFormBegin';
	}

	private function matchRenderFormEndEcho(Echo_ $node): bool
	{
		if (count($node->exprs) !== 1 || !$node->exprs[0] instanceof StaticCall) {
			return false;
		}

		$call = $node->exprs[0];

		return $call->class instanceof Name
			&& $call->class->toString() === self::FORMS_RUNTIME_CLASS
			&& $call->name instanceof Identifier
			&& $call->name->toString() === 'renderFormEnd';
	}

	private function rebuildContainerPush(Expr $expr): ?Assign
	{
		if (!$expr instanceof Assign || !$this->isFormsStackPush($expr->var)) {
			return null;
		}

		if (!$expr->expr instanceof Assign) {
			return null;
		}

		$inner = $expr->expr;
		if (!$inner->var instanceof Variable || !is_string($inner->var->name)) {
			return null;
		}

		$lookup = $this->matchFormsStackFieldLookup($inner->expr);
		if ($lookup === null) {
			return null;
		}

		return new Assign(
			$inner->var,
			new StaticCall(
				new FullyQualified(self::HELPERS_CLASS),
				new Identifier('formContainer'),
				[new Arg($lookup)],
			),
		);
	}

	private function isContainerPopDrop(Expr $expr): bool
	{
		return $expr instanceof FuncCall
			&& $this->isFuncCallNamed($expr, 'array_pop')
			&& count($expr->args) === 1
			&& $expr->args[0] instanceof Arg
			&& GlobalPropertyFetchMatcher::matches($expr->args[0]->value, 'formsStack');
	}

	private function isContainerReassignDrop(Expr $expr): bool
	{
		if (!$expr instanceof Assign || !$this->isVariableNamed($expr->var, 'formContainer')) {
			return false;
		}

		$call = $expr->expr;

		return $call instanceof FuncCall
			&& $this->isFuncCallNamed($call, 'end')
			&& count($call->args) === 1
			&& $call->args[0] instanceof Arg
			&& GlobalPropertyFetchMatcher::matches($call->args[0]->value, 'formsStack');
	}

	private function rebuildInputAssign(Expr $expr): ?Assign
	{
		if (!$expr instanceof Assign || !$this->isVariableNamed($expr->var, self::INPUT_RENAME)) {
			return null;
		}

		if (
			!$expr->expr instanceof Assign
			|| !$expr->expr->var instanceof Variable
			|| $expr->expr->var->name !== '_input'
		) {
			return null;
		}

		$lookup = $this->matchFormsStackFieldLookup($expr->expr->expr);
		if ($lookup === null) {
			return null;
		}

		return new Assign(
			$expr->var,
			new Assign(
				$expr->expr->var,
				new StaticCall(
					new FullyQualified(self::HELPERS_CLASS),
					new Identifier('formField'),
					[new Arg($lookup)],
				),
			),
		);
	}

	private function rebuildFieldEcho(Echo_ $node): ?Echo_
	{
		if (count($node->exprs) !== 1 || !$node->exprs[0] instanceof MethodCall) {
			return null;
		}

		$call = $node->exprs[0];
		$lookup = $this->matchFormsStackFieldLookup($call->var);
		if ($lookup === null) {
			return null;
		}

		$newCall = new MethodCall(
			new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('formField'), [new Arg($lookup)]),
			$call->name,
			$call->args,
		);

		return new Echo_([$newCall]);
	}

	private function rebuildLabelIf(If_ $node): ?If_
	{
		if ($node->elseifs !== [] || $node->else !== null || count($node->stmts) !== 1) {
			return null;
		}

		if (!$node->cond instanceof Assign || !$this->isVariableNamed($node->cond->var, self::LABEL_RENAME)) {
			return null;
		}

		$call = $node->cond->expr;
		if (
			!$call instanceof MethodCall
			|| !$call->name instanceof Identifier
			|| $call->name->toString() !== 'getLabel'
		) {
			return null;
		}

		$lookup = $this->matchFormsStackFieldLookup($call->var);
		if ($lookup === null) {
			return null;
		}

		$bodyStmt = $node->stmts[0];
		if (
			!$bodyStmt instanceof Echo_
			|| count($bodyStmt->exprs) !== 1
			|| !$this->isVariableNamed($bodyStmt->exprs[0], self::LABEL_RENAME)
		) {
			return null;
		}

		$newCond = new Assign(
			$node->cond->var,
			new MethodCall(
				new StaticCall(
					new FullyQualified(self::HELPERS_CLASS),
					new Identifier('formField'),
					[new Arg($lookup)],
				),
				$call->name,
				[],
			),
		);

		return new If_($newCond, ['stmts' => $node->stmts]);
	}

	private function matchFormsStackFieldLookup(Expr $expr): ?Expr
	{
		if (!$expr instanceof ArrayDimFetch || $expr->dim === null) {
			return null;
		}

		if (
			!$expr->var instanceof FuncCall
			|| !$this->isFuncCallNamed($expr->var, 'end')
			|| count($expr->var->args) !== 1
		) {
			return null;
		}

		$arg = $expr->var->args[0];

		return $arg instanceof Arg && GlobalPropertyFetchMatcher::matches(
			$arg->value,
			'formsStack',
		) ? $expr->dim : null;
	}

	private function isFuncCallNamed(FuncCall $node, string $name): bool
	{
		return $node->name instanceof Name && $node->name->toString() === $name;
	}

	private function isVariableNamed(?Node $node, string $name): bool
	{
		return $node instanceof Variable && $node->name === $name;
	}

}
