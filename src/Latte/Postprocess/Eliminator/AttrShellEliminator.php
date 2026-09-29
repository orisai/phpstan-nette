<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
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
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use function array_merge;
use function array_slice;
use function count;
use function is_string;

final class AttrShellEliminator extends EliminatorVisitor
{

	// n:attr's attribute printer: the $ʟ_tmp array assignment before it is inlined as its first argument.
	public const ROLE_ATTRIBUTES = 'attributes';

	// n:tag's tag-name validator assigned to $ʟ_tmp; the temp is renamed in the three statements
	// that make up the opening tag.
	public const ROLE_TAG_CHANGE = 'tagChange';

	// The per-element closing-tag temp indexed by element: $ʟ_tag[N] -> $latteTagN.
	public const ROLE_TAG_ARRAY = 'tagArray';

	// The scalar closing-tag temp: $ʟ_tag -> $latteTag.
	public const ROLE_TAG_SCALAR = 'tagScalar';

	// The per-element snapshot of the scalar temp: $ʟ_tags[N] -> $latteTagN.
	public const ROLE_TAG_SNAPSHOT = 'tagSnapshot';

	private const TMP_TEMP = "\u{29F}_tmp";

	private const IFCONTENT_TEMP = "\u{29F}_ifc";

	private const TAG_VARIABLE_PREFIX = 'latteTag';

	private const TAG_NAME_VARIABLE = 'latteTagName';

	private const HELPERS_CLASS = Helpers::class;

	public function describePattern(): string
	{
		return 'n:class ($ʟ_tmp = array_filter([...])) ? \' class="\'.implode(...).\'"\' : "" ternary echo -> '
			. 'echo \OriPhpstan\Nette\Latte\Runtime\Helpers::classes([...]); n:attr $ʟ_tmp = [...]; echo <attributes>($ʟ_tmp, ...) '
			. 'pair -> echo <attributes>([...], ...); n:tag closing-tag temps renamed to $latteTag/$latteTagN everywhere and '
			. 'the <tagChange> result temp to $latteTagName (the validating call kept analyzed); n:nonce '
			. 'echo $this->global->uiNonce ? ... : "" dropped; n:ifcontent doubly-nested ob_start/try/finally '
			. '$ʟ_ifc[N] = rtrim(ob_get_flush()) === \'\' + outer if ($ʟ_ifc[N] ?? null) { ob_end_clean(); } else '
			. '{ echo ob_get_clean(); } shell -> element body statements only: ' . $this->patterns()->describe();
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
		}

		if ($node instanceof Foreach_) {
			$node->stmts = $this->normalizeStmts($node->stmts);
		}

		if ($node instanceof Echo_) {
			$rewritten = $this->rewriteNClassEcho($node);
			if ($rewritten !== null) {
				return $rewritten;
			}

			if ($this->isNonceEcho($node)) {
				return NodeVisitor::REMOVE_NODE;
			}
		}

		if ($node instanceof ArrayDimFetch && $node->dim instanceof Int_ && $this->isIndexedTagTemp($node->var)) {
			return new Variable(self::TAG_VARIABLE_PREFIX . $node->dim->value);
		}

		if (
			$node instanceof Variable
			&& is_string($node->name)
			&& $this->patterns()->hasName(self::ROLE_TAG_SCALAR, $node->name)
		) {
			return new Variable(self::TAG_VARIABLE_PREFIX);
		}

		return null;
	}

	private function isIndexedTagTemp(Node $node): bool
	{
		if (!$node instanceof Variable || !is_string($node->name)) {
			return false;
		}

		return $this->patterns()->hasName(self::ROLE_TAG_ARRAY, $node->name)
			|| $this->patterns()->hasName(self::ROLE_TAG_SNAPSHOT, $node->name);
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

			$attrEcho = $next instanceof Echo_ ? $this->matchNAttrPair($stmt, $next) : null;
			if ($attrEcho !== null) {
				$result[] = $attrEcho;
				$i += 2;

				continue;
			}

			if ($next instanceof TryCatch) {
				$ifcontentBody = $this->matchIfContentShell($stmt, $next);
				if ($ifcontentBody !== null) {
					foreach ($this->normalizeStmts($ifcontentBody) as $bodyStmt) {
						$result[] = $bodyStmt;
					}

					$i += 2;

					continue;
				}
			}

			if ($this->isTagChangeAssign($stmt)) {
				$window = $this->renameVariable(array_slice($stmts, $i, 3), self::TMP_TEMP, self::TAG_NAME_VARIABLE);
				$result = array_merge($result, $window);
				$i += count($window);

				continue;
			}

			$result[] = $stmt;
			$i++;
		}

		return $result;
	}

	private function matchNAttrPair(Stmt $stmt, Echo_ $next): ?Echo_
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return null;
		}

		if (!$this->isVariableNamed($stmt->expr->var, self::TMP_TEMP) || !$stmt->expr->expr instanceof Array_) {
			return null;
		}

		if (count($next->exprs) !== 1) {
			return null;
		}

		$call = $next->exprs[0];
		if (!$call instanceof StaticCall || !$this->isRoleCall(self::ROLE_ATTRIBUTES, $call) || $call->args === []) {
			return null;
		}

		return new Echo_([
			new StaticCall(
				new FullyQualified($call->class->toString()),
				$call->name->toString(),
				array_merge([new Arg($stmt->expr->expr)], array_slice($call->args, 1)),
			),
		]);
	}

	private function isTagChangeAssign(Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return false;
		}

		$call = $stmt->expr->expr;

		return $this->isVariableNamed($stmt->expr->var, self::TMP_TEMP)
			&& $call instanceof StaticCall
			&& $this->isRoleCall(self::ROLE_TAG_CHANGE, $call);
	}

	/**
	 * @phpstan-assert-if-true Name $call->class
	 * @phpstan-assert-if-true Identifier $call->name
	 */
	private function isRoleCall(string $role, StaticCall $call): bool
	{
		if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
			return false;
		}

		return $this->patterns()->isStaticCall($role, $call->class->toString(), $call->name->toString());
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function renameVariable(array $stmts, string $from, string $to): array
	{
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new class ($from, $to) extends NodeVisitorAbstract {

			private string $from;

			private string $to;

			public function __construct(string $from, string $to)
			{
				$this->from = $from;
				$this->to = $to;
			}

			public function leaveNode(Node $node): ?Node
			{
				if ($node instanceof Variable && $node->name === $this->from) {
					return new Variable($this->to, $node->getAttributes());
				}

				return null;
			}

		});

		/** @var array<Stmt> $renamed */
		$renamed = $traverser->traverse($stmts);

		return $renamed;
	}

	private function rewriteNClassEcho(Echo_ $node): ?Echo_
	{
		if (count($node->exprs) !== 1) {
			return null;
		}

		$classesArg = $this->matchNClassTernary($node->exprs[0]);
		if ($classesArg === null) {
			return null;
		}

		return new Echo_([
			new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('classes'), [
				new Arg($classesArg),
			]),
		]);
	}

	private function matchNClassTernary(Expr $expr): ?Expr
	{
		if (!$expr instanceof Ternary || $expr->if === null) {
			return null;
		}

		if (!$expr->cond instanceof Assign || !$this->isVariableNamed($expr->cond->var, self::TMP_TEMP)) {
			return null;
		}

		$call = $expr->cond->expr;
		if (!$call instanceof FuncCall || !$this->isFuncCallNamed($call, 'array_filter') || count($call->args) !== 1) {
			return null;
		}

		if (!$expr->else instanceof String_ || $expr->else->value !== '') {
			return null;
		}

		$arg = $call->args[0];

		return $arg instanceof Arg ? $arg->value : null;
	}

	private function isNonceEcho(Echo_ $node): bool
	{
		if (count($node->exprs) !== 1 || !$node->exprs[0] instanceof Ternary) {
			return false;
		}

		$ternary = $node->exprs[0];

		return $this->isUiNonceFetch(
			$ternary->cond,
		) && $ternary->else instanceof String_ && $ternary->else->value === '';
	}

	private function isUiNonceFetch(Expr $expr): bool
	{
		if (
			!$expr instanceof PropertyFetch
			|| !$expr->name instanceof Identifier
			|| $expr->name->toString() !== 'uiNonce'
		) {
			return false;
		}

		$global = $expr->var;

		return $global instanceof PropertyFetch
			&& $global->name instanceof Identifier
			&& $global->name->toString() === 'global'
			&& $this->isVariableNamed($global->var, 'this');
	}

	/**
	 * @return array<Stmt>|null
	 */
	private function matchIfContentShell(Stmt $obStart, TryCatch $tryCatch): ?array
	{
		if (
			!NoopObStart::matches($obStart, $this->patterns())
			|| count($tryCatch->catches) !== 0
			|| $tryCatch->finally === null
		) {
			return null;
		}

		if (!$this->isIfContentFinally($tryCatch->finally)) {
			return null;
		}

		return $this->stripInnerIfContentShell($tryCatch->stmts);
	}

	private function isIfContentFinally(Finally_ $finally): bool
	{
		if (count($finally->stmts) !== 1 || !$finally->stmts[0] instanceof If_) {
			return false;
		}

		$if = $finally->stmts[0];
		if ($if->elseifs !== [] || $if->else === null) {
			return false;
		}

		if (!$this->isIfContentCondition($if->cond)) {
			return false;
		}

		if (count($if->stmts) !== 1 || !$this->isBareFuncCall($if->stmts[0], 'ob_end_clean')) {
			return false;
		}

		$elseStmts = $if->else->stmts;

		return count($elseStmts) === 1 && $this->isEchoObGetClean($elseStmts[0]);
	}

	private function isIfContentCondition(Expr $expr): bool
	{
		if (!$expr instanceof Coalesce || !$expr->left instanceof ArrayDimFetch) {
			return false;
		}

		if (!$this->isVariableNamed($expr->left->var, self::IFCONTENT_TEMP) || !$expr->left->dim instanceof Int_) {
			return false;
		}

		return $expr->right instanceof ConstFetch && $expr->right->name->toString() === 'null';
	}

	private function isEchoObGetClean(Stmt $stmt): bool
	{
		return $stmt instanceof Echo_ && count($stmt->exprs) === 1 && $this->isBareFuncCallExpr(
			$stmt->exprs[0],
			'ob_get_clean',
		);
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function stripInnerIfContentShell(array $stmts): array
	{
		$result = [];
		$count = count($stmts);
		$i = 0;

		while ($i < $count) {
			$stmt = $stmts[$i];
			$next = $stmts[$i + 1] ?? null;

			if ($next instanceof TryCatch) {
				$innerBody = $this->matchInnerIfContentShell($stmt, $next);
				if ($innerBody !== null) {
					foreach ($innerBody as $bodyStmt) {
						$result[] = $bodyStmt;
					}

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
	private function matchInnerIfContentShell(Stmt $obStart, TryCatch $tryCatch): ?array
	{
		if (!$this->isBareObStart($obStart) || count($tryCatch->catches) !== 0 || $tryCatch->finally === null) {
			return null;
		}

		$finallyStmts = $tryCatch->finally->stmts;
		if (count($finallyStmts) !== 1) {
			return null;
		}

		$assign = $finallyStmts[0];
		if (!$assign instanceof Expression || !$assign->expr instanceof Assign) {
			return null;
		}

		$target = $assign->expr->var;
		if (!$target instanceof ArrayDimFetch || !$this->isVariableNamed($target->var, self::IFCONTENT_TEMP)) {
			return null;
		}

		return $this->isRtrimEmptyCheck($assign->expr->expr) ? $tryCatch->stmts : null;
	}

	private function isRtrimEmptyCheck(Expr $expr): bool
	{
		if (!$expr instanceof Identical || !$expr->right instanceof String_ || $expr->right->value !== '') {
			return false;
		}

		$rtrim = $expr->left;
		if (!$rtrim instanceof FuncCall || !$this->isFuncCallNamed($rtrim, 'rtrim') || count($rtrim->args) !== 1) {
			return false;
		}

		$arg = $rtrim->args[0];

		return $arg instanceof Arg && $this->isBareFuncCallExpr($arg->value, 'ob_get_flush');
	}

	private function isBareObStart(Stmt $stmt): bool
	{
		return $stmt instanceof Expression
			&& $stmt->expr instanceof FuncCall
			&& $this->isFuncCallNamed($stmt->expr, 'ob_start')
			&& count($stmt->expr->args) === 0;
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
