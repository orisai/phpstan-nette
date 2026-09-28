<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\GlobalPropertyFetchMatcher;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use function array_key_exists;
use function array_pop;
use function count;
use function in_array;
use function is_string;
use function ucfirst;

final class BlockDispatchEliminator extends EliminatorVisitor
{

	private const HELPERS_CLASS = Helpers::class;

	/** @var array<string, ClassMethod> */
	private array $methodsByName = [];

	/** @var array<ClassMethod> */
	private array $methodStack = [];

	public function describePattern(): string
	{
		return "\$this->renderBlock('name', get_defined_vars()[, contentType[, 'snippet']]) call sites (both the "
			. 'in-place 2-arg block-declaration render and the {include #block[, args]} 3-arg/4-arg forms) -> direct '
			. '$this->blockName(...) call, threading the callee\'s header-var params by name from the caller\'s own '
			. 'params and its own (define/positional-include) params from the vars-array argument, in declaration '
			. 'order; unresolvable call sites (dynamic block name, cross-file callee, unrecognized vars-array shape) '
			. 'are left as renderBlock(...) with the get_defined_vars() argument flattened to []; snippetDriver '
			. 'enter(...)/try/finally leave() shell -> try body statements only, calls dropped; '
			. '$this->global->snippetDriver->getHtmlId(<name expr>) -> Helpers::snippetId(<name expr>); '
			. '{embed file|block} enterBlockLayer(...)/dead if(false){} mirror/[copyBlockLayer()]/try{createTemplate(...)'
			. '->renderToContentType(...)|renderBlock(...)}finally{leaveBlockLayer()} shell -> try body statements only '
			. '(already-resolved direct block calls kept as-is by the renderBlock handling above), layer calls dropped; '
			. 'file-form createTemplate(<target>, <args>, "embed")->renderToContentType(...) -> Helpers::embedTemplate('
			. '<target>), <args> kept analyzed via a preceding Helpers::analyzed(<args>) statement';
	}

	/**
	 * @param array<Node> $nodes
	 * @return array<Node>|null
	 */
	public function beforeTraverse(array $nodes)
	{
		$this->methodsByName = [];
		$this->methodStack = [];

		foreach ($nodes as $node) {
			if (!$node instanceof Class_) {
				continue;
			}

			foreach ($node->stmts as $stmt) {
				if ($stmt instanceof ClassMethod) {
					$this->methodsByName[$stmt->name->toString()] = $stmt;
				}
			}
		}

		return null;
	}

	/**
	 * @return Node|null
	 */
	public function enterNode(Node $node)
	{
		if ($node instanceof ClassMethod) {
			$this->methodStack[] = $node;
		}

		return null;
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

		if ($node instanceof Expression && $node->expr instanceof MethodCall && $this->isRenderBlockCall($node->expr)) {
			$replacement = $this->rebuildDirectCall($node->expr) ?? $this->dropGetDefinedVarsArg($node->expr);
			if ($replacement !== null) {
				return new Expression($replacement);
			}
		}

		if ($node instanceof MethodCall && $this->isSnippetGetHtmlIdCall($node)) {
			return $this->buildSnippetIdCall($node);
		}

		if ($node instanceof ClassMethod) {
			array_pop($this->methodStack);
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

			if ($this->matchSnippetEnter($stmt) && $next instanceof TryCatch) {
				$body = $this->matchSnippetLeaveShell($next);
				if ($body !== null) {
					foreach ($this->normalizeStmts($body) as $bodyStmt) {
						$result[] = $bodyStmt;
					}

					$i += 2;

					continue;
				}
			}

			$embedShell = $this->tryMatchEmbedShell($stmts, $i);
			if ($embedShell !== null) {
				[$consumed, $bodyStmts] = $embedShell;
				foreach ($bodyStmts as $bodyStmt) {
					$result[] = $bodyStmt;
				}

				$i += $consumed;

				continue;
			}

			$result[] = $stmt;
			$i++;
		}

		return $result;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array{int, array<Stmt>}|null
	 */
	private function tryMatchEmbedShell(array $stmts, int $i): ?array
	{
		if (!$this->matchEnterBlockLayer($stmts[$i] ?? null)) {
			return null;
		}

		$deadIf = $stmts[$i + 1] ?? null;
		if (!$deadIf instanceof If_ || !$this->isLiteralFalse($deadIf->cond)) {
			return null;
		}

		$cursor = $i + 2;
		if ($this->matchCopyBlockLayer($stmts[$cursor] ?? null)) {
			$cursor++;
		}

		$try = $stmts[$cursor] ?? null;
		if (!$try instanceof TryCatch) {
			return null;
		}

		$body = $this->matchLeaveBlockLayerShell($try);
		if ($body === null) {
			return null;
		}

		return [$cursor + 1 - $i, $this->normalizeStmts($this->rebuildEmbedBody($body))];
	}

	private function matchEnterBlockLayer(?Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return false;
		}

		$call = $stmt->expr;
		$varsArg = $call->args[1] ?? null;

		return $this->isVariableNamed($call->var, 'this')
			&& $call->name instanceof Identifier
			&& $call->name->toString() === 'enterBlockLayer'
			&& count($call->args) === 2
			&& $varsArg instanceof Arg
			&& $varsArg->value instanceof FuncCall
			&& $this->isFuncCallNamed($varsArg->value, 'get_defined_vars');
	}

	private function isLiteralFalse(Expr $expr): bool
	{
		return $expr instanceof ConstFetch && $expr->name->toString() === 'false';
	}

	private function matchCopyBlockLayer(?Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return false;
		}

		$call = $stmt->expr;

		return $this->isVariableNamed($call->var, 'this')
			&& $call->name instanceof Identifier
			&& $call->name->toString() === 'copyBlockLayer'
			&& count($call->args) === 0;
	}

	/**
	 * @return array<Stmt>|null
	 */
	private function matchLeaveBlockLayerShell(TryCatch $node): ?array
	{
		if (count($node->catches) !== 0 || $node->finally === null || count($node->finally->stmts) !== 1) {
			return null;
		}

		$leaveStmt = $node->finally->stmts[0];
		if (!$leaveStmt instanceof Expression || !$leaveStmt->expr instanceof MethodCall) {
			return null;
		}

		$call = $leaveStmt->expr;
		$isLeave = $this->isVariableNamed($call->var, 'this')
			&& $call->name instanceof Identifier
			&& $call->name->toString() === 'leaveBlockLayer'
			&& count($call->args) === 0;

		return $isLeave ? $node->stmts : null;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function rebuildEmbedBody(array $stmts): array
	{
		$result = [];
		foreach ($stmts as $stmt) {
			$rebuilt = $this->rebuildCreateTemplateCall($stmt);
			if ($rebuilt !== null) {
				foreach ($rebuilt as $rebuiltStmt) {
					$result[] = $rebuiltStmt;
				}

				continue;
			}

			$result[] = $stmt;
		}

		return $result;
	}

	/**
	 * @return array<Stmt>|null
	 */
	private function rebuildCreateTemplateCall(Stmt $stmt): ?array
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return null;
		}

		$renderCall = $stmt->expr;
		if (!$renderCall->name instanceof Identifier || $renderCall->name->toString() !== 'renderToContentType') {
			return null;
		}

		$createCall = $renderCall->var;
		if (
			!$createCall instanceof MethodCall
			|| !$this->isVariableNamed($createCall->var, 'this')
			|| !$createCall->name instanceof Identifier
			|| $createCall->name->toString() !== 'createTemplate'
			|| count($createCall->args) !== 3
		) {
			return null;
		}

		$targetArg = $createCall->args[0];
		$argsArg = $createCall->args[1];
		if (!$targetArg instanceof Arg || !$argsArg instanceof Arg) {
			return null;
		}

		$result = [];
		if (!$this->isEmptyArrayLiteral($argsArg->value)) {
			$result[] = new Expression(
				new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('analyzed'), [$argsArg]),
			);
		}

		$result[] = new Expression(
			new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('embedTemplate'), [$targetArg]),
		);

		return $result;
	}

	private function isEmptyArrayLiteral(Expr $expr): bool
	{
		return $expr instanceof Array_ && $expr->items === [];
	}

	private function matchSnippetEnter(Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return false;
		}

		$call = $stmt->expr;

		return GlobalPropertyFetchMatcher::matches($call->var, 'snippetDriver')
			&& $call->name instanceof Identifier
			&& $call->name->toString() === 'enter'
			&& count($call->args) === 2;
	}

	/**
	 * @return array<Stmt>|null
	 */
	private function matchSnippetLeaveShell(TryCatch $node): ?array
	{
		if (count($node->catches) !== 0 || $node->finally === null || count($node->finally->stmts) !== 1) {
			return null;
		}

		$leaveStmt = $node->finally->stmts[0];
		if (!$leaveStmt instanceof Expression || !$leaveStmt->expr instanceof MethodCall) {
			return null;
		}

		$call = $leaveStmt->expr;
		$isLeave = GlobalPropertyFetchMatcher::matches($call->var, 'snippetDriver')
			&& $call->name instanceof Identifier
			&& $call->name->toString() === 'leave'
			&& count($call->args) === 0;

		return $isLeave ? $node->stmts : null;
	}

	private function isRenderBlockCall(MethodCall $node): bool
	{
		return $this->isVariableNamed($node->var, 'this')
			&& $node->name instanceof Identifier
			&& $node->name->toString() === 'renderBlock'
			&& count($node->args) >= 2;
	}

	private function isSnippetGetHtmlIdCall(MethodCall $node): bool
	{
		return GlobalPropertyFetchMatcher::matches($node->var, 'snippetDriver')
			&& $node->name instanceof Identifier
			&& $node->name->toString() === 'getHtmlId'
			&& count($node->args) === 1;
	}

	private function buildSnippetIdCall(MethodCall $node): StaticCall
	{
		return new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('snippetId'), $node->args);
	}

	private function rebuildDirectCall(MethodCall $node): ?MethodCall
	{
		$nameArg = $node->args[0] ?? null;
		if (!$nameArg instanceof Arg || !$nameArg->value instanceof String_) {
			return null;
		}

		$methodName = 'block' . ucfirst($nameArg->value->value);
		$calleeMethod = $this->methodsByName[$methodName] ?? null;
		if ($calleeMethod === null) {
			return null;
		}

		$callerMethod = $this->methodStack[count($this->methodStack) - 1] ?? null;
		if ($callerMethod === null) {
			return null;
		}

		$callerParamNames = [];
		foreach ($callerMethod->params as $callerParam) {
			if ($callerParam->var instanceof Variable && is_string($callerParam->var->name)) {
				$callerParamNames[] = $callerParam->var->name;
			}
		}

		$varsArg = $node->args[1] ?? null;
		$extraValues = $varsArg instanceof Arg ? $this->extractExtraValues($varsArg->value) : null;
		if ($extraValues === null) {
			return null;
		}

		// F4 (args-channel) params DeclarationInjector::extraBlockArgTypes() adds are keyed by NAME
		// (the union of every {include name: expr} site's own arg names), never by declaration
		// order the way a block's OWN {define} param list is - so those callee params must be
		// matched against the vars array's OWN string keys, not the position-based cursor below
		// (own {define} params keep the position-based match: real Latte threads them positionally,
		// per macroDefine's own $ʟ_args[N] ?? $ʟ_args['name'] ?? default formula, and cursor-matching
		// already covers that correctly).
		$namedValues = $varsArg instanceof Arg ? $this->extractNamedValues($varsArg->value) : [];

		$newArgs = [];
		$cursor = 0;

		foreach ($calleeMethod->params as $param) {
			if (!$param->var instanceof Variable || !is_string($param->var->name)) {
				return null;
			}

			$paramName = $param->var->name;

			// This call site's own explicit arg (checked first) always wins over a same-named
			// caller local (round-1 parity probe testImportExplicitIncludeArgWinsOverParamAndLocal:
			// extract($ʟ_args) runs after extract($this->params)/the caller's own locals, so a
			// same-named explicit arg overwrites them at runtime) - a RECURSIVE self-include (the
			// callee's own params shadow the caller's identically-named ones, e.g. a block including
			// itself) would otherwise thread the caller's OWN unrelated value through instead of this
			// call's own argument.
			if (array_key_exists($paramName, $namedValues)) {
				$newArgs[] = new Arg($namedValues[$paramName]);

				continue;
			}

			if (in_array($paramName, $callerParamNames, true)) {
				$newArgs[] = new Arg(new Variable($paramName));

				continue;
			}

			if (!array_key_exists($cursor, $extraValues)) {
				return null;
			}

			$newArgs[] = new Arg($extraValues[$cursor]);
			$cursor++;
		}

		return new MethodCall(new Variable('this'), $methodName, $newArgs);
	}

	/**
	 * @return array<Expr>|null
	 */
	private function extractExtraValues(Expr $expr): ?array
	{
		if (
			$expr instanceof FuncCall
			&& $this->isFuncCallNamed($expr, 'get_defined_vars')
			&& count($expr->args) === 0
		) {
			return [];
		}

		if ($expr instanceof Array_) {
			return $this->arrayItemValues($expr);
		}

		if ($expr instanceof Plus && $expr->left instanceof Array_) {
			return $this->arrayItemValues($expr->left);
		}

		return null;
	}

	// String-keyed items are excluded: they are matched by NAME via extractNamedValues() instead
	// (own {define} params stay positional-only per real Latte, but interleaving them in the source
	// with F4's name-keyed extras must not shift a positional own param's cursor index - counting
	// only the genuinely positional items keeps the two matching strategies independent of each
	// other's presence or array order).

	/**
	 * @return array<Expr>
	 */
	private function arrayItemValues(Array_ $array): array
	{
		$values = [];
		foreach ($array->items as $item) {
			if (!$item->key instanceof String_) {
				$values[] = $item->value;
			}
		}

		return $values;
	}

	/**
	 * @return array<string, Expr>
	 */
	private function extractNamedValues(Expr $expr): array
	{
		$array = null;
		if ($expr instanceof Array_) {
			$array = $expr;
		} elseif ($expr instanceof Plus && $expr->left instanceof Array_) {
			$array = $expr->left;
		}

		if ($array === null) {
			return [];
		}

		$values = [];
		foreach ($array->items as $item) {
			if ($item->key instanceof String_) {
				$values[$item->key->value] = $item->value;
			}
		}

		return $values;
	}

	private function dropGetDefinedVarsArg(MethodCall $node): ?MethodCall
	{
		$arg = $node->args[1] ?? null;
		if (
			!$arg instanceof Arg
			|| !$arg->value instanceof FuncCall
			|| !$this->isFuncCallNamed($arg->value, 'get_defined_vars')
		) {
			return null;
		}

		$newArgs = $node->args;
		$newArgs[1] = new Arg(new Array_([]));

		return new MethodCall($node->var, $node->name, $newArgs);
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
