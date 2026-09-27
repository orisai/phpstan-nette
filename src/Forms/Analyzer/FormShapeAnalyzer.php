<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use Nette\Forms\Container as NetteContainer;
use OriPhpstan\Nette\Component\Attachment\AttachmentTransitions;
use OriPhpstan\Nette\Component\Attachment\ContainerLazyRead;
use OriPhpstan\Nette\Forms\Cache\FormsCodeVersion;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Cache\TypeCanonicalizer;
use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;
use OriPhpstan\Nette\Forms\Component\ConstructedFormShapeResolver;
use OriPhpstan\Nette\Forms\Component\ConstructorFormShapeResolver;
use OriPhpstan\Nette\Forms\Component\LiteralNameResolver;
use OriPhpstan\Nette\Forms\Graph\ClosureScope;
use OriPhpstan\Nette\Forms\Graph\ComponentAffectingNodeVisitor;
use OriPhpstan\Nette\Forms\Graph\ComponentHandleUses;
use OriPhpstan\Nette\Forms\Graph\ContainerRegistrationDetector;
use OriPhpstan\Nette\Forms\Graph\EventCallbackStore;
use OriPhpstan\Nette\Forms\Graph\FirstClassCallableDetector;
use OriPhpstan\Nette\Forms\Graph\MethodChainSpine;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummary;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Graph\NodeId;
use OriPhpstan\Nette\Forms\Graph\RegisteringMethodName;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use OriPhpstan\Nette\Forms\Graph\VariableBindingCounter;
use OriPhpstan\Nette\Forms\Graph\VariableEscapeDetector;
use OriPhpstan\Nette\Forms\Inference\EnclosingFunctionLikeLocator;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_ as StringLiteral;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function end;
use function implode;
use function in_array;
use function is_file;
use function is_string;
use function ltrim;
use function sha1;
use function sha1_file;
use function sort;
use function spl_object_id;
use function strncmp;
use function strpos;
use function strtolower;
use function substr;
use function usort;

final class FormShapeAnalyzer
{

	private const CACHE_VERSION = 'v3';

	private const REBIND_CLEAN = 'clean';

	private const REBIND_OPEN = 'open';

	private NodeContributionSummaryFactory $factory;

	private FormShapeCache $cache;

	private CalleeShapeResolver $callees;

	/** @var array<string, true> */
	private array $activeCallees = [];

	private ?string $walkOwnerClass = null;

	private ?LocalVariableClassTracker $classTracker = null;

	private ?ConstructedFormShapeResolver $constructedResolver = null;

	/** @var array<string, bool> */
	private array $registersOnThis = [];

	/** @var array<string, string> */
	private array $literalNameEnv = [];

	/** @var array<int, true> */
	private array $eventStoredCallbackIds = [];

	private bool $inAbsorbedClosureBody = false;

	/** @var array<string, true> */
	private array $absorbedBodyBoundNames = [];

	/** @var array<string, int> */
	private array $activeWalkNames = [];

	public function __construct(
		NodeContributionSummaryFactory $factory,
		FormShapeCache $cache,
		CalleeShapeResolver $callees
	)
	{
		$this->factory = $factory;
		$this->cache = $cache;
		$this->callees = $callees;
	}

	/**
	 * The resolver a `new X()` origin is shaped by, assembled from the collaborators this analyzer was
	 * already given rather than injected beside them: every construction site of this class — the
	 * funnel, ContainerModel's two direct ones and the harnesses — then reaches the same answer, and a
	 * site that wired no resolver could not silently keep the empty-closed shape this exists to remove.
	 */
	private function constructedResolver(): ConstructedFormShapeResolver
	{
		return $this->constructedResolver ??= new ConstructedFormShapeResolver(
			$this->factory->getReflectionProvider(),
			new ConstructorFormShapeResolver(
				$this->factory->getReflectionProvider(),
				$this->factory->getCatalog(),
				$this->callees->getRichParser(),
				$this->cache,
			),
		);
	}

	private function classTracker(): LocalVariableClassTracker
	{
		if ($this->classTracker === null) {
			$this->classTracker = new LocalVariableClassTracker(
				$this->factory->getReflectionProvider(),
				$this->cache->recorder(),
			);
		}

		return $this->classTracker;
	}

	/**
	 * Scope-free class of the entry form variable, resolved from its defining site so the
	 * recompute yields the same class no matter which scope (defining or a foreign consumer's)
	 * triggers it. Returns null when the owner is unknown (plain-file scope) or the defining
	 * site is unreadable by reflection/AST, leaving the live-scope path to resolve it.
	 */
	private function trackedEntryClass(Expr $formExpr, FunctionLike $functionLike, ?string $ownerFqcn): ?string
	{
		if (!$formExpr instanceof Variable || !is_string($formExpr->name)) {
			return null;
		}

		return $this->classTracker()->resolveContainerClass($formExpr->name, $functionLike, $ownerFqcn);
	}

	private function codeVersion(): string
	{
		return FormsCodeVersion::get($this->cache->defaultContainerClass());
	}

	/**
	 * @param list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $taggedRecords
	 */
	public function analyzeFormValue(
		Expr $formExpr,
		FunctionLike $functionLike,
		array $taggedRecords,
		Scope $scope,
		?string $astFile = null,
		?string $ownerFqcn = null,
		bool $scopeFree = false,
		?string $entryClassFallback = null
	): FormShape
	{
		$trackedClass = $scopeFree
			? $this->trackedEntryClass($formExpr, $functionLike, $ownerFqcn)
			: null;

		$type = $scope->getType($formExpr);
		$classes = $type->getObjectClassNames();
		sort($classes);
		$isForm = $classes !== [] && (new ObjectType(NetteContainer::class))->isSuperTypeOf($type)->yes();

		$fallbackClassName = $trackedClass;
		if ($fallbackClassName === null && !$isForm) {
			$fallbackClassName = $this->constructedFormClass($formExpr, $functionLike, $scope);

			// A scope-free entry the class tracker cannot resolve (a form rebound more than once) and
			// whose foreign scope cannot type it would otherwise bail to a closed-empty shape before the
			// walk runs, dropping the rebind marker the walk would set. The caller-supplied declared
			// return class lets the walk proceed and degrade OPEN, matching the store's in-scope walk.
			if ($fallbackClassName === null && $scopeFree) {
				$fallbackClassName = $entryClassFallback;
			}

			if ($fallbackClassName === null) {
				return FormShape::empty('\\' . ($classes[0] ?? 'mixed'));
			}
		}

		$className = $fallbackClassName ?? (count($classes) === 1 ? $classes[0] : implode('|', $classes));

		$trackedName = $formExpr instanceof Variable && is_string($formExpr->name) ? $formExpr->name : null;

		if ($trackedName === null) {
			return CompositionState::initial()->withUnknownReason(UnknownReason::UNRESOLVED_ORIGIN)->toFormShape(
				$className,
			);
		}

		$recordsByNode = [];
		foreach ($taggedRecords as $record) {
			$recordsByNode[spl_object_id($record['node'])] = $record;
		}

		$rootStmts = $functionLike->getStmts() ?? [];

		$file = $astFile ?? $scope->getFile();
		$fileHash = is_file($file) ? (string) sha1_file($file) : sha1($file);
		$key = self::CACHE_VERSION . '|' . $this->codeVersion() . '|' . NodeId::functionLikeKey(
			$astFile ?? '',
			$functionLike,
		) . '#' . $trackedName;

		return $this->cache->remember(
			$fileHash,
			$key,
			function () use (
				$rootStmts,
				$trackedName,
				$recordsByNode,
				$className,
				$scopeFree,
				$formExpr,
				$functionLike,
				$ownerFqcn,
				$scope,
				$file
			): FormShape {
				if ($scopeFree) {
					// Rerun the tracked-class resolution the caller already did above: that
					// earlier call ran before remember() opened the dependency frame, so its
					// reflection reads were silently dropped. Re-running here (only on a cache
					// miss) lands them in this entry's recorded dependencies.
					$this->trackedEntryClass($formExpr, $functionLike, $ownerFqcn);
				}

				$this->literalNameEnv = $this->buildLiteralNameEnv($rootStmts);
				$this->walkOwnerClass = $this->callees->enclosingClassName($file, $functionLike);
				$this->eventStoredCallbackIds = $this->eventStoredCallbackNodeIds(
					$rootStmts,
					$trackedName,
					$recordsByNode,
				);
				$returns = new ReturnPointCollector();
				$this->activeWalkNames[$trackedName] = ($this->activeWalkNames[$trackedName] ?? 0) + 1;
				try {
					$result = $this->walkStmts(
						$rootStmts,
						CompositionState::initial(),
						new WalkContext($trackedName, $className, $recordsByNode, $rootStmts, $scope),
						$returns,
					);
				} finally {
					$this->activeWalkNames[$trackedName]--;
					$this->walkOwnerClass = null;
				}

				$this->literalNameEnv = [];
				$this->eventStoredCallbackIds = [];

				$shape = $this->mergeReturnPoints($result, $returns)->toFormShape($className);
				$mappedType = MappedTypeDetector::detectTopLevel($rootStmts, $trackedName);
				if ($mappedType !== null) {
					$shape = $shape->withMappedType($mappedType);
				}

				return TypeCanonicalizer::canonicalizeShape($shape);
			},
		);
	}

	/**
	 * Folds the form's final shape across every reachable `return $trackedName` point
	 * (recorded during the walk) plus the implicit fall-through end, so a field added on
	 * only some return paths becomes MAYBE rather than over-claimed PRESENT.
	 *
	 * @param array{state: CompositionState, terminated: bool, bindings?: array<string, ControlBinding>} $result
	 */
	private function mergeReturnPoints(array $result, ReturnPointCollector $returns): CompositionState
	{
		$states = $returns->getStates();
		if (!$result['terminated']) {
			$states[] = $result['state'];
		}

		if ($states === []) {
			return $result['state'];
		}

		if (count($states) === 1) {
			return $states[0];
		}

		return CompositionState::joinReturnPoints($states);
	}

	/**
	 * @param list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $taggedRecords
	 */
	public function analyzeContainerParam(
		string $paramName,
		FunctionLike $functionLike,
		string $className,
		array $taggedRecords,
		Scope $scope,
		?string $astFile = null
	): FormShape
	{
		$recordsByNode = [];
		foreach ($taggedRecords as $record) {
			$recordsByNode[spl_object_id($record['node'])] = $record;
		}

		$rootStmts = $functionLike->getStmts() ?? [];

		$file = $astFile ?? $scope->getFile();
		$fileHash = is_file($file) ? (string) sha1_file($file) : sha1($file);
		$key = self::CACHE_VERSION . '|param|' . $this->codeVersion() . '|' . NodeId::functionLikeKey(
			$astFile ?? '',
			$functionLike,
		) . '#' . $paramName;

		return $this->cache->remember(
			$fileHash,
			$key,
			function () use ($rootStmts, $paramName, $recordsByNode, $className, $scope, $functionLike, $file): FormShape {
				$this->literalNameEnv = $this->buildLiteralNameEnv($rootStmts);
				$this->walkOwnerClass = $this->callees->enclosingClassName($file, $functionLike);
				$this->eventStoredCallbackIds = $this->eventStoredCallbackNodeIds(
					$rootStmts,
					$paramName,
					$recordsByNode,
				);
				$returns = new ReturnPointCollector();
				$this->activeWalkNames[$paramName] = ($this->activeWalkNames[$paramName] ?? 0) + 1;
				try {
					$result = $this->walkStmts(
						$rootStmts,
						CompositionState::initial(),
						new WalkContext($paramName, $className, $recordsByNode, $rootStmts, $scope),
						$returns,
					);
				} finally {
					$this->activeWalkNames[$paramName]--;
					$this->walkOwnerClass = null;
				}

				$this->literalNameEnv = [];
				$this->eventStoredCallbackIds = [];

				return TypeCanonicalizer::canonicalizeShape(
					$this->mergeReturnPoints($result, $returns)->toFormShape($className),
				);
			},
		);
	}

	private function constructedFormClass(Expr $formExpr, FunctionLike $functionLike, Scope $scope): ?string
	{
		if (!$formExpr instanceof Variable || !is_string($formExpr->name)) {
			return null;
		}

		$container = new ObjectType(NetteContainer::class);
		$assigns = [];
		foreach ((new NodeFinder())->findInstanceOf($functionLike->getStmts() ?? [], Assign::class) as $assign) {
			if ($assign->var instanceof Variable && $assign->var->name === $formExpr->name) {
				$assigns[] = $assign;
			}
		}

		$found = null;
		$sawNew = false;
		foreach ($assigns as $assign) {
			if (!$assign->expr instanceof New_ || !$assign->expr->class instanceof Node\Name) {
				continue;
			}

			$sawNew = true;
			$class = $assign->expr->class->toString();
			if (!$container->isSuperTypeOf(new ObjectType($class))->yes()) {
				continue;
			}

			if ($found !== null && $found !== $class) {
				return null;
			}

			$found = $class;
		}

		if ($sawNew) {
			return $found;
		}

		foreach ($assigns as $assign) {
			if (!$assign->expr instanceof CallLike) {
				continue;
			}

			$classes = $scope->getType($assign->expr)->getObjectClassNames();
			if (count($classes) !== 1 || !$container->isSuperTypeOf(new ObjectType($classes[0]))->yes()) {
				continue;
			}

			if ($found !== null && $found !== $classes[0]) {
				return null;
			}

			$found = $classes[0];
		}

		return $found;
	}

	private function absorbImmediateInvokedClosure(
		Node\Stmt $stmt,
		WalkContext $ctx,
		CompositionState &$state
	): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof FuncCall) {
			return false;
		}

		$call = $stmt->expr;
		$callee = $call->name;
		if (!$callee instanceof Closure && !$callee instanceof ArrowFunction) {
			return false;
		}

		if ($call->isFirstClassCallable()) {
			return false;
		}

		foreach ($call->getArgs() as $argIdx => $arg) {
			if (!$arg->value instanceof Variable || $arg->value->name !== $ctx->getTrackedName()) {
				continue;
			}

			$param = $callee->getParams()[$argIdx] ?? null;
			if (
				$param === null
				|| !$param->var instanceof Variable
				|| !is_string($param->var->name)
			) {
				continue;
			}

			$innerVar = $param->var->name;
			$bodyStmts = $callee instanceof Closure
				? $callee->stmts
				: [new Expression($callee->expr)];

			$result = $this->walkAbsorbedClosureBody(
				$callee,
				$bodyStmts,
				$state,
				new WalkContext(
					$innerVar,
					$ctx->getTrackedClass(),
					$ctx->getRecordsByNode(),
					$ctx->getRootStmts(),
					$ctx->getScope(),
				),
			);
			$state = $result['state'];

			return true;
		}

		return false;
	}

	/**
	 * A by-ref closure that mutates the form is only sound to absorb when it actually runs:
	 * an immediate invoke `(function () use (&$form) {…})()` or an assignment `$build = …`
	 * whose variable is later called `$build()` is absorbed; if such an assigned closure is
	 * never invoked but escapes the scope (argument / return / property or array store — it
	 * may run elsewhere) the shape is opened; if it neither runs nor escapes its mutations
	 * are dropped. Top-level scan only.
	 *
	 * @param array<string, Closure|ArrowFunction> $byRefClosures
	 */
	private function processByRefClosures(
		Node\Stmt $stmt,
		WalkContext $ctx,
		CompositionState &$state,
		array &$byRefClosures
	): void
	{
		$trackedName = $ctx->getTrackedName();
		$recordsByNode = $ctx->getRecordsByNode();

		if ($stmt instanceof Expression && $stmt->expr instanceof FuncCall) {
			$directCallee = $stmt->expr->name;
			if (
				!$stmt->expr->isFirstClassCallable()
				&& ($directCallee instanceof Closure || $directCallee instanceof ArrowFunction)
				&& $this->byRefMutatesForm($directCallee, $trackedName, $recordsByNode)
			) {
				$this->absorbClosureBody($directCallee, $ctx, $state);
			}
		}

		if (
			$stmt instanceof Expression
			&& $stmt->expr instanceof Assign
			&& $stmt->expr->var instanceof Variable
			&& is_string($stmt->expr->var->name)
			&& ($stmt->expr->expr instanceof Closure || $stmt->expr->expr instanceof ArrowFunction)
			&& $this->byRefMutatesForm($stmt->expr->expr, $trackedName, $recordsByNode)
		) {
			$byRefClosures[$stmt->expr->var->name] = $stmt->expr->expr;

			return;
		}

		if ($byRefClosures === []) {
			return;
		}

		foreach ($this->invokedClosureVars($stmt) as $invoked) {
			if (isset($byRefClosures[$invoked])) {
				$this->absorbClosureBody(
					$byRefClosures[$invoked],
					$ctx,
					$state,
				);
			}
		}

		foreach (array_keys($byRefClosures) as $varName) {
			if ($this->closureVarEscapes($stmt, $varName)) {
				$state = $state->withUnknownReason(UnknownReason::FORM_ALIASED);
			}
		}
	}

	/**
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 */
	private function byRefMutatesForm(Node $closure, string $trackedName, array $recordsByNode): bool
	{
		if (!$closure instanceof Closure || !$this->capturesByRef($closure, $trackedName)) {
			return false;
		}

		foreach ($closure->stmts as $bodyStmt) {
			if ($this->taggedRecordsIn($bodyStmt, $trackedName, $recordsByNode) !== []) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param Closure|ArrowFunction $closure
	 */
	private function absorbClosureBody(
		Node $closure,
		WalkContext $ctx,
		CompositionState &$state
	): void
	{
		$bodyStmts = $closure instanceof Closure ? $closure->stmts : [new Expression($closure->expr)];
		$result = $this->walkAbsorbedClosureBody(
			$closure,
			$bodyStmts,
			$state,
			$ctx,
		);
		$state = $result['state'];
	}

	/**
	 * @param Closure|ArrowFunction $closure
	 * @param array<Node\Stmt> $bodyStmts
	 * @return array{state: CompositionState, terminated: bool, bindings: array<string, ControlBinding>}
	 */
	private function walkAbsorbedClosureBody(
		Node $closure,
		array $bodyStmts,
		CompositionState $state,
		WalkContext $ctx
	): array
	{
		$trackedName = $ctx->getTrackedName();
		$rootStmts = $ctx->getRootStmts();

		// A closure body is its own variable scope: neither the outer env nor the outer
		// record scope may resolve variable names inside it; only the body's own
		// single-assignment literals (plus sound arrow-fn by-value carry-ins) apply.
		$outerEnv = $this->literalNameEnv;
		$outerMask = $this->inAbsorbedClosureBody;
		$outerBound = $this->absorbedBodyBoundNames;
		$this->literalNameEnv = $this->closureBodyLiteralNameEnv($closure, $bodyStmts, $outerEnv);
		$this->inAbsorbedClosureBody = true;

		$bound = [];
		foreach (array_keys($this->countVariableBindings($bodyStmts)) as $boundName) {
			$bound[$boundName] = true;
		}

		// A by-value capture snapshots the outer variable at the closure's definition, but the
		// shared record scope types it at a later program point; when the outer function rebinds
		// the name (assigns it more than once) the snapshot and the scope typing can diverge, so
		// distrust that name in value channels inside the body. Params (re-bound per call) and
		// by-ref captures (the shared variable, whose body writes are already masked) keep scope
		// typing.
		$fixed = $this->closureFixedBindingNames($closure);
		foreach ($this->countVariableBindings($rootStmts, false) as $name => $writeCount) {
			if ($writeCount > 1 && !isset($fixed[$name])) {
				$bound[$name] = true;
			}
		}

		$this->absorbedBodyBoundNames = $bound + $outerBound;
		$this->activeWalkNames[$trackedName] = ($this->activeWalkNames[$trackedName] ?? 0) + 1;
		try {
			return $this->walkStmts($bodyStmts, $state, $ctx);
		} finally {
			$this->activeWalkNames[$trackedName]--;
			$this->literalNameEnv = $outerEnv;
			$this->inAbsorbedClosureBody = $outerMask;
			$this->absorbedBodyBoundNames = $outerBound;
		}
	}

	/**
	 * @param Closure|ArrowFunction $closure
	 * @param array<Node\Stmt> $bodyStmts
	 * @param array<string, string> $outerEnv
	 * @return array<string, string>
	 */
	private function closureBodyLiteralNameEnv(Node $closure, array $bodyStmts, array $outerEnv): array
	{
		$env = $this->buildLiteralNameEnv($bodyStmts);

		if ($closure instanceof ArrowFunction) {
			// Implicit by-value capture: an outer single-assignment literal not rebound
			// by the body is provably that literal inside it.
			$bound = $this->countVariableBindings($bodyStmts);
			foreach ($outerEnv as $name => $value) {
				if (!isset($bound[$name])) {
					$env[$name] = $value;
				}
			}
		}

		// Params and use-clause imports are bindings the body-level scan cannot see.
		foreach ($closure->getParams() as $param) {
			if ($param->var instanceof Variable && is_string($param->var->name)) {
				unset($env[$param->var->name]);
			}
		}

		if ($closure instanceof Closure) {
			foreach ($closure->uses as $use) {
				if (is_string($use->var->name)) {
					unset($env[$use->var->name]);
				}
			}
		}

		return $env;
	}

	/**
	 * @param Closure|ArrowFunction $closure
	 * @return array<string, true>
	 */
	private function closureFixedBindingNames(Node $closure): array
	{
		$names = [];
		foreach ($closure->getParams() as $param) {
			if ($param->var instanceof Variable && is_string($param->var->name)) {
				$names[$param->var->name] = true;
			}
		}

		if ($closure instanceof Closure) {
			foreach ($closure->uses as $use) {
				if ($use->byRef && is_string($use->var->name)) {
					$names[$use->var->name] = true;
				}
			}
		}

		return $names;
	}

	/**
	 * @return list<string>
	 */
	private function invokedClosureVars(Node\Stmt $stmt): array
	{
		$vars = [];
		foreach ((new NodeFinder())->findInstanceOf([$stmt], FuncCall::class) as $call) {
			if ($call->isFirstClassCallable()) {
				continue;
			}

			if ($call->name instanceof Variable && is_string($call->name->name)) {
				$vars[] = $call->name->name;
			}
		}

		return $vars;
	}

	private function closureVarEscapes(Node\Stmt $stmt, string $varName): bool
	{
		if ($stmt instanceof Return_ && $stmt->expr instanceof Variable && $stmt->expr->name === $varName) {
			return true;
		}

		$finder = new NodeFinder();

		// `$build(...)` does not invoke the closure; it aliases it into a new Closure that
		// may run anywhere later.
		foreach ($finder->findInstanceOf([$stmt], FuncCall::class) as $call) {
			if (
				$call->isFirstClassCallable()
				&& $call->name instanceof Variable
				&& $call->name->name === $varName
			) {
				return true;
			}
		}

		foreach ($finder->findInstanceOf([$stmt], Assign::class) as $assign) {
			if (
				(
					$assign->var instanceof Node\Expr\PropertyFetch
					|| $assign->var instanceof Node\Expr\StaticPropertyFetch
					|| $assign->var instanceof ArrayDimFetch
				)
				&& $assign->expr instanceof Variable
				&& $assign->expr->name === $varName
			) {
				return true;
			}
		}

		return VariableEscapeDetector::passedAsDirectArgument(
			$stmt,
			$varName,
			static fn (CallLike $call): bool => $call instanceof FuncCall
				&& $call->name instanceof Variable
				&& $call->name->name === $varName,
		);
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 * @param array<string, ControlBinding> $bindings
	 * @return array{state: CompositionState, terminated: bool, bindings: array<string, ControlBinding>}
	 */
	private function walkStmts(
		array $stmts,
		CompositionState $state,
		WalkContext $ctx,
		?ReturnPointCollector $returns = null,
		array $bindings = []
	): array
	{
		$trackedName = $ctx->getTrackedName();
		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();

		$stmts = array_values($stmts);
		/** @var array<string, true> $aliasNames */
		$aliasNames = [];
		/** @var array<string, Closure|ArrowFunction> $byRefClosures */
		$byRefClosures = [];
		$reboundAway = false;
		foreach ($stmts as $index => $stmt) {
			// after $c = $c->add*(...) the tracked var points at the returned child, not the
			// container; a later add on it would mis-attribute, so open rather than apply
			if ($reboundAway) {
				if ($this->taggedRecordsIn($stmt, $trackedName, $recordsByNode) !== []) {
					$state = $state->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);
				}

				continue;
			}

			$selfReboundAdd = $this->isSelfRootedAddReassignment($stmt, $trackedName, $recordsByNode);

			if (!$selfReboundAdd) {
				$rebind = $this->trackedRebindKind($stmt, $trackedName);
				if ($rebind === self::REBIND_CLEAN) {
					if (!$state->isInitial()) {
						$state = CompositionState::initial();
						$aliasNames = [];
						$bindings = [];
					}

					$state = $this->absorbConstruction(
						$state,
						$this->cleanRebindConstruction($stmt, $trackedName),
					);
				} elseif ($rebind === self::REBIND_OPEN) {
					$state = CompositionState::initial()->withUnknownReason(UnknownReason::REBIND_UNPROVEN);
					$aliasNames = [];
					$bindings = [];
				}
			}

			foreach ($this->aliasTargetsOf($stmt, $trackedName) as $aliasName) {
				$aliasNames[$aliasName] = true;
			}

			if ($aliasNames !== [] && $this->mutatesAliasName($stmt, $aliasNames)) {
				$state = $state->withUnknownReason(UnknownReason::FORM_ALIASED);
			}

			if ($this->callsDynamicMethodOnTracked($stmt, $trackedName)) {
				$state = $state->withUnknownReason(UnknownReason::DYNAMIC_METHOD);
			}

			// `$form->m(...)` closes over the tracked receiver — an escape: the closure may
			// invoke the method later with unknown arguments, so the shape opens.
			if (FirstClassCallableDetector::onTrackedReceiver($stmt, $trackedName)) {
				$state = $state->withUnknownReason(UnknownReason::FIRST_CLASS_CALLABLE);
			}

			if ($this->callsFormDisablerOnTracked($stmt, $trackedName, $trackedClass)) {
				$state = $state->withAllSlotsOmitted();
			}

			if ($this->callsUnknownMethodOnTracked($stmt, $trackedName, $trackedClass)) {
				$state = $state->withUnknownReason(UnknownReason::EXTENSION_METHOD);
			}

			if ($this->callsDeclaredAdderOnTracked($stmt, $trackedName, $trackedClass)) {
				$state = $state->withUnknownReason(UnknownReason::DECLARED_ADD_UNREAD);
			}

			$state = $this->applyRegisteringMethodOnTracked($stmt, $state, $ctx);

			$this->processByRefClosures($stmt, $ctx, $state, $byRefClosures);

			if ($this->absorbImmediateInvokedClosure($stmt, $ctx, $state)) {
				continue;
			}

			foreach ($this->trackedArgumentCalls($stmt, $trackedName) as $passed) {
				$contribution = $passed['index'] === null
					? null
					: $this->calleeContribution($passed['call'], $passed['index'], $ctx);

				$state = $contribution === null
					? $state->withUnknownReason(UnknownReason::UNFOLLOWED_CALL)
					: $state->absorbCalleeContribution($contribution);
			}

			$state = $this->applyCallbackTiming($stmt, $state, $ctx);

			if (
				$this->unassignedByRefClosureEscapes($stmt, $trackedName)
				|| $this->trackedVarStoreEscapes($stmt, $ctx)
			) {
				$state = $state->withUnknownReason(UnknownReason::FORM_ALIASED);
			}

			// A by-value capture of the tracked name whose body then rebinds it keeps the
			// captured object reachable on some paths while the records are excluded from this
			// walk (rebindingClosureInternalNodeIds), so the shape must open rather than
			// under-claim.
			if ($this->valueCapturedRebindAmbiguity($stmt, $trackedName)) {
				$state = $state->withUnknownReason(UnknownReason::FORM_ALIASED);
			}

			if ($this->pulledChildCanGainFields($stmt, $state, $ctx)) {
				$state = $state->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);
			}

			// The attachment of the references this statement mentions, advanced beside the shape and
			// read by nobody yet. It sits after the rebind handling above (a REBIND_CLEAN resets the
			// state, and the construction that caused the rebind has to be recorded into the state that
			// survives) and before the branch dispatch below, so a branching statement contributes its
			// header here and its arms through their own walk.
			$state = $state->withAttachment(AttachmentTransitions::apply($state->getAttachment(), $stmt));

			$state = $this->applyStatementHeaders($stmt, $state, $ctx);

			if ($stmt instanceof If_) {
				$result = $this->joinConditional(
					$stmt,
					$state,
					$ctx,
					$returns,
					$bindings,
				);
				$state = $this->openPostArmContainers(
					$stmt,
					array_slice($stmts, $index + 1),
					$result['state'],
					$trackedName,
				);
				$bindings = $result['bindings'];

				continue;
			}

			if ($stmt instanceof Switch_) {
				$result = $this->joinSwitch(
					$stmt,
					$state,
					$ctx,
					$returns,
					$bindings,
				);
				$state = $this->openPostArmContainers(
					$stmt,
					array_slice($stmts, $index + 1),
					$result['state'],
					$trackedName,
				);
				$bindings = $result['bindings'];

				continue;
			}

			if ($stmt instanceof TryCatch) {
				$result = $this->joinTryCatch(
					$stmt,
					$state,
					$ctx,
					$returns,
					$bindings,
				);
				$state = $this->openPostArmContainers(
					$stmt,
					array_slice($stmts, $index + 1),
					$result['state'],
					$trackedName,
				);
				$bindings = $result['bindings'];

				continue;
			}

			if ($stmt instanceof Foreach_ || $stmt instanceof While_ || $stmt instanceof Do_ || $stmt instanceof For_) {
				$body = $this->walkStmts(
					$stmt->stmts,
					$state,
					$ctx,
					$returns,
					$bindings,
				);
				$state = $this->openPostArmContainers(
					$stmt,
					array_slice($stmts, $index + 1),
					$body['state']->asLoopBody($state),
					$trackedName,
				);
				$bindings = $this->joinBindings($bindings, [$body['bindings']], true);

				continue;
			}

			$state = $this->applyBoundModifierStatement($stmt, $state, $bindings, $trackedName, $trackedClass);
			$state = $this->applyStatement($stmt, $state, $ctx);
			$this->rebindControlVariables($stmt, $ctx, $bindings);
			$state = $this->attachChildShapes(
				$stmt,
				array_slice($stmts, $index + 1),
				$state,
				$ctx,
			);

			if ($selfReboundAdd) {
				$reboundAway = true;
			}

			if (
				$stmt instanceof Return_
				|| ($stmt instanceof Expression && $stmt->expr instanceof Throw_)
				|| $stmt instanceof Break_
				|| $stmt instanceof Continue_
			) {
				if ($returns !== null && $this->returnsTrackedVariable($stmt, $trackedName)) {
					$returns->record($state);
				}

				return ['state' => $state, 'terminated' => true, 'bindings' => $bindings];
			}
		}

		return ['state' => $state, 'terminated' => false, 'bindings' => $bindings];
	}

	private function returnsTrackedVariable(Node\Stmt $stmt, string $trackedName): bool
	{
		return $stmt instanceof Return_
			&& $stmt->expr instanceof Variable
			&& $stmt->expr->name === $trackedName;
	}

	/**
	 * @param array<Node\Stmt> $followingStmts
	 */
	private function attachChildShapes(
		Node\Stmt $stmt,
		array $followingStmts,
		CompositionState $state,
		WalkContext $ctx
	): CompositionState
	{
		if (!$stmt instanceof Expression) {
			return $state;
		}

		$trackedName = $ctx->getTrackedName();
		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();
		$rootStmts = $ctx->getRootStmts();

		foreach ($this->taggedRecordsIn($stmt, $trackedName, $recordsByNode) as $rec) {
			$summary = $this->summarizeRecord($rec, $trackedName, $trackedClass);
			$resolution = $summary->getResolution();
			$name = $summary->getName();
			if ($name === null || $resolution === null) {
				continue;
			}

			if ($resolution->getKind() === ControlValueResolution::KIND_CONTAINER) {
				$state = $this->composeContainer(
					$stmt->expr,
					$rec['node'],
					$rec['scope'],
					$name,
					$followingStmts,
					$state,
					$ctx,
				);

				continue;
			}

			if ($resolution->getKind() === ControlValueResolution::KIND_REPLICATOR) {
				$state = $this->composeReplicator(
					$stmt->expr,
					$rec['node'],
					$rec['scope'],
					$name,
					$followingStmts,
					$state,
					$ctx,
					$resolution->getReplicatorFactoryArgPosition() ?? 1,
					$resolution->getControlClass(),
				);
			}
		}

		return $state;
	}

	/**
	 * @param array<Node\Stmt> $followingStmts
	 */
	private function composeContainer(
		Expr $expr,
		Node $taggedNode,
		Scope $scope,
		string $name,
		array $followingStmts,
		CompositionState $state,
		WalkContext $ctx
	): CompositionState
	{
		if (!$taggedNode instanceof Expr) {
			return $state->markSlotUnresolvedOrigin($name);
		}

		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();
		$rootStmts = $ctx->getRootStmts();

		$childVar = null;

		if ($expr instanceof Assign && $expr->var instanceof Variable && is_string($expr->var->name)) {
			$childVar = $expr->var->name;
		}

		$containerArg = null;
		if ($expr instanceof Assign && $expr->expr instanceof Variable && is_string($expr->expr->name)) {
			$containerArg = $expr->expr;
		} elseif (($addArg = $this->addComponentContainerArg($expr)) !== null) {
			$containerArg = $addArg;
		}

		if ($containerArg instanceof Variable && is_string($containerArg->name)) {
			$rhsName = $containerArg->name;
			$newOrigin = $this->localContainerOrigin($rhsName, $rootStmts);
			if ($newOrigin === null) {
				return $state->markSlotUnresolvedOrigin($name);
			}

			$childClassName = $this->nestedContainerClass(
				$this->classNameOfType($scope->getType($containerArg)),
				$trackedClass,
			);
			$childShape = $this->subWalkVariable(
				new WalkContext($rhsName, $childClassName, $recordsByNode, $rootStmts, $ctx->getScope()),
			);

			return $state->withContainerShape($name, $childShape);
		}

		$childClassName = $this->nestedContainerClass(
			$this->classNameOfType($scope->getType($taggedNode)),
			$trackedClass,
		);

		if ($childVar === null) {
			return $state->withContainerShape(
				$name,
				$this->uncapturedContainerShape($expr, $taggedNode, $childClassName),
			);
		}

		if ($expr instanceof Assign && $this->containerEscapedIntoChainedCall($expr, $taggedNode)) {
			return $state->withContainerShape(
				$name,
				FormShape::empty($childClassName)->withUnknownReason(UnknownReason::CONTAINER_REFERENCE),
			);
		}

		$childShape = $this->subWalk(
			$followingStmts,
			new WalkContext($childVar, $childClassName, $recordsByNode, $rootStmts, $ctx->getScope()),
			$childClassName,
		);

		return $state->withContainerShape($name, $childShape);
	}

	/**
	 * The shape of a container whose add* call bound nothing to a plain local variable, so no
	 * followingStmts sub-walk can follow it. Closed-and-empty is a positive claim that the container
	 * has no children, and the walk may make it in exactly one situation: the tagged add* call IS the
	 * whole statement expression. `$form->addContainer('a');` creates the container and drops the
	 * reference on the floor, so nothing can ever have added to it.
	 *
	 * Every other uncaptured statement hands the reference somewhere the walk does not read, and must
	 * stay OPEN for the same reason containerEscapedIntoChainedCall() opens the captured-but-escaped
	 * case below - a genuinely added child would otherwise read as ABSENT:
	 *
	 * - `$form->addContainer('a')->addText('z');` - the chained call registers 'z' on it. This one was
	 *   a live false positive: `$form['a']['z']` reported "Form component 'z' does not exist." The
	 *   sibling guard below could not catch it because it additionally requires an Assign, so only the
	 *   assigned form `$x = $form->addContainer('a')->addDynamic(…)` was handled.
	 * - `$this->sub = $form->addContainer('a');` - an Assign whose LHS is not a plain variable (a
	 *   property, an array dim, a list destructure) puts the container in a holder this walk does not
	 *   track, and anything added through that holder is unmodelled.
	 * - `helper($form->addContainer('a'));` - the reference is handed to a callee that may add to it.
	 */
	private function uncapturedContainerShape(Expr $expr, Node $taggedNode, string $childClassName): FormShape
	{
		$shape = FormShape::empty($childClassName);

		return $expr === $taggedNode
			? $shape
			: $shape->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);
	}

	/**
	 * True when $childVar (the assign's LHS) is bound not to the container itself but to
	 * whatever another add* call chained onto it — in the SAME statement — returns instead (e.g.
	 * `$x = $form->addContainer('a')->addDynamic('items', fn)`, where $x holds the REPLICATOR,
	 * not the 'a' container). A fluent call that returns the container itself (`setDefaults()`,
	 * `setRequired()`, …) is never tagged (ComponentAffectingNodeVisitor only tags add-prefixed/
	 * removeComponent calls), so it never trips this — only a chained call that is ITSELF a
	 * recognised component-adding operation does. The container reference escaped without ever
	 * being captured on its own, the same "further mutations unknown" situation
	 * composeReplicatorOwnShape() already opens for the analogous replicator-own-shape case: this
	 * set must stay OPEN, never closed-and-empty, since a genuinely-added child (like 'items'
	 * here) would otherwise read as absent.
	 */
	private function containerEscapedIntoChainedCall(Assign $expr, Node $taggedNode): bool
	{
		return $expr->expr !== $taggedNode
			&& $expr->expr->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode;
	}

	private function nestedContainerClass(string $scopeResolved, string $trackedClass): string
	{
		if ($scopeResolved !== '') {
			return $scopeResolved;
		}

		if (
			$trackedClass === ''
			|| strpos($trackedClass, '|') !== false
			|| !(new ObjectType(NetteContainer::class))
				->isSuperTypeOf(new ObjectType($trackedClass))
				->yes()
		) {
			return $scopeResolved;
		}

		return $this->cache->defaultContainerClass();
	}

	/**
	 * @param array<Node\Stmt> $followingStmts
	 */
	private function composeReplicator(
		Expr $expr,
		Node $taggedNode,
		Scope $scope,
		string $name,
		array $followingStmts,
		CompositionState $state,
		WalkContext $ctx,
		int $factoryArgPosition,
		?string $replicatorClass
	): CompositionState
	{
		$recordsByNode = $ctx->getRecordsByNode();
		$rootStmts = $ctx->getRootStmts();

		$factoryArg = $this->replicatorFactoryArg($taggedNode, $factoryArgPosition);
		$ownShape = $this->composeReplicatorOwnShape(
			$expr,
			$taggedNode,
			$scope,
			$followingStmts,
			$ctx,
			$replicatorClass,
		);

		if ($factoryArg instanceof Closure || $factoryArg instanceof ArrowFunction) {
			$param = $factoryArg->getParams()[0] ?? null;

			// The ROW class is whatever the item factory declares its first parameter to be, and null
			// when it declares nothing - an undeclared parameter is a class this walk does not know,
			// not a class named "mixed". Naming it that put an ObjectType over a nonexistent class
			// into every row type built from the shape (FormShapeProjector::replicatorType() takes the
			// null as its cue to build no row type at all now), and left FormShape::describe()
			// printing `array<int, mixed{…}>` for a row whose class is simply unknown. WalkContext's
			// own tracked-class slot is an analysis parameter rather than an answer and has no null
			// spelling; '' is the one composeReplicatorOwnShape() already uses for "unknown" there.
			$rowClassName = $param !== null && $param->type instanceof Node\Name
				? $param->type->toString()
				: null;
			$innerVar = $param !== null && $param->var instanceof Variable && is_string($param->var->name)
				? $param->var->name
				: null;

			$bodyStmts = $factoryArg instanceof Closure
				? $factoryArg->stmts
				: [new Expression($factoryArg->expr)];

			if ($innerVar === null) {
				$innerShape = FormShape::empty($rowClassName);
			} else {
				$result = $this->walkAbsorbedClosureBody(
					$factoryArg,
					$bodyStmts,
					CompositionState::initial(),
					new WalkContext($innerVar, $rowClassName ?? '', $recordsByNode, $rootStmts, $ctx->getScope()),
				);
				$innerShape = $result['state']->toFormShape($rowClassName);
			}

			if ($innerVar !== null) {
				$mappedType = MappedTypeDetector::detect($bodyStmts, $innerVar);
				if ($mappedType !== null) {
					$innerShape = $innerShape->withMappedType($mappedType);
				}
			}

			return $state->withReplicatorShape($name, new ReplicatorShape($innerShape, $ownShape));
		}

		// The item factory is not an inline closure (a callable array, a variable, a first-class
		// callable), so the ROW's children cannot be enumerated at all. NON_ENUMERABLE_CLOSURE opens
		// the FORM, which says nothing about the row: a closed empty row is a positive claim that the
		// replicator's items have no fields, so `$form['rep'][0]['q']` read as a non-existent component
		// on correct code. CONTAINER_REFERENCE is the lost-field degrade openReplicatorPlaceholder()
		// already gives both halves for this same "a container is here, its children are not known"
		// job; NON_ENUMERABLE_CLOSURE cannot take its place because it deliberately stays out of
		// LOST_FIELD_UNKNOWN_REASONS - on the FORM it promises further names, it does not lose known
		// ones.
		$innerClassName = $this->cache->defaultContainerClass();
		$innerShape = FormShape::empty($innerClassName)->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);

		return $state
			->withReplicatorShape($name, new ReplicatorShape($innerShape, $ownShape))
			->withUnknownReason(UnknownReason::NON_ENUMERABLE_CLOSURE);
	}

	/**
	 * The replicator CONTAINER's own children - e.g. `$reservationsParameters->addSubmit('addNode',
	 * …)` called on the addDynamic()/addMultiplier() RETURN VALUE, as opposed to inside the
	 * item-factory closure walked above (which contributes to the ROW instead). Mirrors
	 * composeContainer()'s childVar tracking: only a direct `$var = …` capture of the tagged call
	 * can have further statements attached to it in code, so a discarded or fluently-chained
	 * return value opens instead (UnknownReason::CONTAINER_REFERENCE) - the holder escaped without
	 * being tracked, so its own children are unmodelled, not provably absent. A closed empty shape
	 * here would tell the rule a genuinely-added own child does not exist.
	 *
	 * The receiver's class prefers scope->getType($taggedNode) (precise: the replicator's exact
	 * declared return type, e.g. an app-specific subclass) but falls back to the resolution's own
	 * getControlClass() - the SAME value CompositionState::applySequential() already writes into
	 * componentTypes[$name] - when that scope is unavailable. A replicator reached through a
	 * container's own followingStmts sub-walk (this one nested inside composeContainer()'s) sees
	 * an imprecise (mixed) scope for a call carrying a closure argument, same as any tagged node
	 * reached this way; without the fallback, an empty tracked class here would leave every control
	 * added straight onto such a nested replicator opaque (KIND_UNKNOWN_TYPE) instead of correctly
	 * omitted or valued.
	 *
	 * @param array<Node\Stmt> $followingStmts
	 */
	private function composeReplicatorOwnShape(
		Expr $expr,
		Node $taggedNode,
		Scope $scope,
		array $followingStmts,
		WalkContext $ctx,
		?string $replicatorClass
	): FormShape
	{
		if (!$expr instanceof Assign || !$expr->var instanceof Variable || !is_string($expr->var->name)) {
			// The holder escaped without being captured to a variable (discarded, or fluently
			// chained onto in the same statement) — any control added onto it is unmodelled, the
			// same "reference escaped, further mutations unknown" situation openContainerShape()
			// opens a plain container for. This set must stay OPEN, never closed-and-empty: a
			// closed empty shape would let the rule classify a genuinely-added own child as absent.
			return FormShape::empty()->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);
		}

		$ownClassName = $taggedNode instanceof Expr ? $this->classNameOfType($scope->getType($taggedNode)) : '';
		if ($ownClassName === '') {
			$ownClassName = $replicatorClass ?? '';
		}

		return $this->subWalk(
			$followingStmts,
			new WalkContext(
				$expr->var->name,
				$ownClassName,
				$ctx->getRecordsByNode(),
				$ctx->getRootStmts(),
				$ctx->getScope(),
			),
			$ownClassName,
		);
	}

	/**
	 * The replicator's item-factory closure, wherever it sits: a direct argument of an add* call
	 * ($form->addDynamic('x', fn)), or a constructor argument of an instantiated replicator
	 * container reached through an offset assignment ($form['x'] = new Rep(fn)) or
	 * addComponent(new Rep(fn), 'x'). Keeps the three add mechanisms equal.
	 */
	private function replicatorFactoryArg(Node $taggedNode, int $factoryArgPosition): ?Expr
	{
		if ($taggedNode instanceof Assign) {
			return $taggedNode->expr instanceof New_ ? $this->newClosureArg($taggedNode->expr) : null;
		}

		if ($taggedNode instanceof MethodCall || $taggedNode instanceof NullsafeMethodCall) {
			if ($taggedNode->name instanceof Identifier && $taggedNode->name->toString() === 'addComponent') {
				$first = $taggedNode->getArgs()[0]->value ?? null;

				return $first instanceof New_ ? $this->newClosureArg($first) : null;
			}

			return $taggedNode->getArgs()[$factoryArgPosition]->value ?? null;
		}

		return null;
	}

	private function newClosureArg(New_ $new): ?Expr
	{
		foreach ($new->getArgs() as $arg) {
			if ($arg->value instanceof Closure || $arg->value instanceof ArrowFunction) {
				return $arg->value;
			}
		}

		return null;
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 */
	private function subWalk(
		array $stmts,
		WalkContext $ctx,
		string $className
	): FormShape
	{
		$trackedName = $ctx->getTrackedName();
		$this->activeWalkNames[$trackedName] = ($this->activeWalkNames[$trackedName] ?? 0) + 1;
		try {
			$result = $this->walkStmts($stmts, CompositionState::initial(), $ctx);
		} finally {
			$this->activeWalkNames[$trackedName]--;
		}

		return $result['state']->toFormShape($className);
	}

	private function subWalkVariable(WalkContext $ctx): FormShape
	{
		return $this->subWalk($ctx->getRootStmts(), $ctx, $ctx->getTrackedClass());
	}

	private function addComponentContainerArg(Expr $expr): ?Expr
	{
		if (
			($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
			&& $expr->name instanceof Identifier
			&& $expr->name->toString() === 'addComponent'
		) {
			return $expr->getArgs()[0]->value ?? null;
		}

		return null;
	}

	/**
	 * @param array<Node\Stmt> $rootStmts
	 */
	private function localContainerOrigin(string $variableName, array $rootStmts): ?New_
	{
		foreach ((new NodeFinder())->findInstanceOf($rootStmts, Assign::class) as $assign) {
			if (
				$assign->var instanceof Variable
				&& $assign->var->name === $variableName
				&& $assign->expr instanceof New_
				&& $assign->expr->class instanceof Node\Name
				&& (new ObjectType(NetteContainer::class))
					->isSuperTypeOf(new ObjectType($assign->expr->class->toString()))
					->yes()
			) {
				return $assign->expr;
			}
		}

		return null;
	}

	private function classNameOfType(Type $type): string
	{
		$classes = $type->getObjectClassNames();
		sort($classes);

		return count($classes) === 1 ? $classes[0] : implode('|', $classes);
	}

	private function applyStatement(
		Node\Stmt $stmt,
		CompositionState $state,
		WalkContext $ctx
	): CompositionState
	{
		$expr = null;
		if ($stmt instanceof Expression) {
			$expr = $stmt->expr;
		} elseif ($stmt instanceof Return_) {
			$expr = $stmt->expr;
		}

		if ($expr instanceof Ternary) {
			$arms = [];
			$arms[] = $this->branchExprResult($expr->if ?? $expr->cond, $state, $ctx);
			$arms[] = $this->branchExprResult($expr->else, $state, $ctx);

			return CompositionState::joinBranches($state, $arms, false);
		}

		if ($expr instanceof Coalesce) {
			$arm = $this->branchExprResult($expr->right, $state, $ctx);

			return CompositionState::joinBranches($state, [$arm], true);
		}

		if ($expr instanceof AssignCoalesce) {
			$arm = $this->branchExprResult($expr->expr, $state, $ctx);

			return CompositionState::joinBranches($state, [$arm], true);
		}

		if ($expr instanceof Match_) {
			$arms = [];
			$hasDefault = false;
			foreach ($expr->arms as $matchArm) {
				if ($matchArm->conds === null) {
					$hasDefault = true;
				}

				$arms[] = $this->branchExprResult($matchArm->body, $state, $ctx);
			}

			return CompositionState::joinBranches($state, $arms, !$hasDefault);
		}

		return $this->applyTaggedIn($stmt, $state, $ctx);
	}

	/**
	 * @param array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode} $rec
	 * @param list<array{tip: Expr, startDepth: int, certainty: Certainty::*}> $extraChains
	 */
	private function summarizeRecord(
		array $rec,
		string $trackedName,
		string $trackedClass,
		array $extraChains = [],
		bool $controlEscaped = false
	): NodeContributionSummary
	{
		return $this->factory->fromTaggedNode(
			$rec['stmt'],
			$rec['node'],
			$rec['tagged'],
			$rec['scope'],
			$trackedName,
			$trackedClass,
			$this->literalNameEnv,
			$extraChains,
			$controlEscaped,
			$this->inAbsorbedClosureBody,
			$this->absorbedBodyBoundNames,
		);
	}

	/**
	 * When the walk reaches a statement that applies a separate-statement or Rules-variable
	 * modifier/rule to a currently-bound value control (`$c->setNullable()`, `$c->addRule(...)`,
	 * `$r->addRule(...)`, `$c->addConditionOn(...)->addRule(...)`), the chain is appended to that
	 * control's binding in source order and its slot is re-folded from the original add node and
	 * every chain seen so far — the same fold as inline-chained modifiers — and replaces the slot
	 * in the current state. A statement that leaks the control (passes it as an argument, stores,
	 * returns or aliases it, or weaves it into a chain's arguments) escapes the binding and opens
	 * its slot. Because the walk reaches each statement on its own control-flow path, a modifier
	 * inside a branch refines the slot in that branch's state and joinBranches unions it with the
	 * other arm; the chain's own Nette condition depth still distinguishes a widening rule from a
	 * type-replacing one. The binding map is threaded through the walk and joined beside the shape.
	 *
	 * @param array<string, ControlBinding> $bindings
	 */
	private function applyBoundModifierStatement(
		Node\Stmt $stmt,
		CompositionState $state,
		array &$bindings,
		string $trackedName,
		string $trackedClass
	): CompositionState
	{
		if ($bindings === []) {
			return $state;
		}

		if (!$stmt instanceof Expression) {
			return $this->escapeReferencingBindings($stmt, $bindings, $state, $trackedName, $trackedClass);
		}

		$expr = $stmt->expr;
		$assignedVar = null;
		if ($expr instanceof Assign && $expr->var instanceof Variable && is_string($expr->var->name)) {
			$assignedVar = $expr->var->name;
			$expr = $expr->expr;
		}

		foreach ($bindings as $controlVar => $binding) {
			if ($binding->isEscaped() || $assignedVar === $controlVar) {
				// a flat reassignment of the bound variable rebinds it (handled after the
				// shape effect); the new control, not this one, receives later modifiers
				continue;
			}

			// $d = $c aliases the same control to a new variable (propagated after the shape
			// effect), so the control is not leaked and its slot is not opened
			$pureAlias = $assignedVar !== null && $expr instanceof Variable && $expr->name === $controlVar;

			$root = $this->chainRootDepth($expr, $controlVar, $binding->getRulesVars(), $trackedName);
			if ($root === null) {
				if (!$pureAlias && $this->referencesAnyVariable($expr, $controlVar, $binding->getRulesVars())) {
					$bindings[$controlVar] = $binding->escape();
					$state = $this->refoldBinding($bindings[$controlVar], $trackedName, $trackedClass, $state);
				}

				continue;
			}

			$binding = $binding->withChain([
				'tip' => $expr,
				'startDepth' => $root['startDepth'],
				'certainty' => Certainty::HAPPENS,
			]);
			if ($this->chainLeaksVariable($expr, $controlVar, $binding->getRulesVars())) {
				$binding = $binding->escape();
			}

			if ($assignedVar !== null && $assignedVar !== $controlVar) {
				$binding = $binding->withRulesVar($assignedVar, $root['resultDepth']);
			}

			$bindings[$controlVar] = $binding;
			$state = $this->refoldBinding($binding, $trackedName, $trackedClass, $state);

			return $state;
		}

		return $state;
	}

	/**
	 * A non-expression statement (return, echo, throw, …) that mentions a bound control or one
	 * of its Rules variables may hand it elsewhere, so the binding escapes and its slot opens.
	 *
	 * @param array<string, ControlBinding> $bindings
	 */
	private function escapeReferencingBindings(
		Node\Stmt $stmt,
		array &$bindings,
		CompositionState $state,
		string $trackedName,
		string $trackedClass
	): CompositionState
	{
		foreach ($bindings as $controlVar => $binding) {
			if (!$binding->isEscaped() && $this->referencesAnyVariable($stmt, $controlVar, $binding->getRulesVars())) {
				$bindings[$controlVar] = $binding->escape();
				$state = $this->refoldBinding($bindings[$controlVar], $trackedName, $trackedClass, $state);
			}
		}

		return $state;
	}

	/**
	 * Re-folds a bound control's slot from its add node and the chains gathered so far and
	 * replaces the slot in the state; an escaped binding folds to an opened (mixed) slot.
	 * The replacement keeps the slot's current presence — a modifier never proves the add ran.
	 *
	 * A value-LESS control (a bound addSubmit() whose setter chain escapes) has no slot to read that
	 * presence off; its record lives in componentTypes, so its own axis is asked next. Falling
	 * straight to HAPPENS there would let a modifier on a conditionally-added button promote it to
	 * definitely-attached, which is the one direction with no backstop behind it.
	 */
	private function refoldBinding(
		ControlBinding $binding,
		string $trackedName,
		string $trackedClass,
		CompositionState $state
	): CompositionState
	{
		$summary = $this->summarizeRecord(
			$binding->getRecord(),
			$trackedName,
			$trackedClass,
			$binding->getChains(),
			$binding->isEscaped(),
		);

		$name = $summary->getName();
		$presence = $name === null
			? Certainty::HAPPENS
			: ($state->slotPresence($name) ?? $state->componentTypePresence($name) ?? Certainty::HAPPENS);

		return $state->applySequential($summary, true, $presence);
	}

	/**
	 * Maintains the binding environment at a plain statement after its shape effect is applied:
	 * a fresh `$c = $form->add*('x')` binds (or rebinds) the variable to that value control with
	 * an empty chain set; a flat reassignment of a bound variable to anything else drops its
	 * binding; `$d = $c` aliases an existing control binding to the new variable so later
	 * modifiers on either refine the same slot.
	 *
	 * @param array<string, ControlBinding> $bindings
	 */
	private function rebindControlVariables(
		Node\Stmt $stmt,
		WalkContext $ctx,
		array &$bindings
	): void
	{
		$trackedName = $ctx->getTrackedName();

		$offset = $this->offsetControlBindingRecord($stmt, $ctx);
		if ($offset !== null) {
			// $form['x'] = new TextInput(); a later $form['x']->setRequired() / getComponent('x')
			// modifier attaches through the same chain machinery, keyed on the access path.
			$bindings["\0" . $offset['slot']] = new ControlBinding($offset['record']);

			return;
		}

		if (
			!$stmt instanceof Expression
			|| !$stmt->expr instanceof Assign
			|| !$stmt->expr->var instanceof Variable
			|| !is_string($stmt->expr->var->name)
			|| $stmt->expr->var->name === $trackedName
		) {
			return;
		}

		$assignedVar = $stmt->expr->var->name;
		$rhs = $stmt->expr->expr;

		$record = $this->valueControlBindingRecord($stmt, $ctx);
		if ($record !== null) {
			$bindings[$assignedVar] = new ControlBinding($record);

			return;
		}

		if ($rhs instanceof Variable && is_string($rhs->name) && isset($bindings[$rhs->name])) {
			$source = $bindings[$rhs->name];
			$bindings[$assignedVar] = new ControlBinding(
				$source->getRecord(),
				$source->getChains(),
				[],
				$source->isEscaped(),
			);

			return;
		}

		unset($bindings[$assignedVar]);
	}

	/**
	 * The add tagged-record of a `$control = $form->add*('name')` value-control binding, or null
	 * when the statement is not such a binding. Container/replicator adds keep the existing
	 * child-variable path.
	 *
	 * @return array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}|null
	 */
	private function valueControlBindingRecord(Node\Stmt $stmt, WalkContext $ctx): ?array
	{
		$trackedName = $ctx->getTrackedName();
		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();

		if (
			!$stmt instanceof Expression
			|| !$stmt->expr instanceof Assign
			|| !$stmt->expr->var instanceof Variable
			|| !is_string($stmt->expr->var->name)
			|| $stmt->expr->var->name === $trackedName
		) {
			return null;
		}

		$addNode = $stmt->expr->expr;
		if (
			(!$addNode instanceof MethodCall && !$addNode instanceof NullsafeMethodCall)
			|| !$this->targetsTrackedVariable($addNode, $trackedName)
		) {
			return null;
		}

		$records = $this->taggedRecordsIn($stmt, $trackedName, $recordsByNode);
		if ($records === [] || $records[0]['node'] !== $addNode) {
			return null;
		}

		$summary = $this->summarizeRecord($records[0], $trackedName, $trackedClass);
		$resolution = $summary->getResolution();
		if ($resolution === null || $resolution->getKind() !== ControlValueResolution::KIND_VALUE) {
			return null;
		}

		return $records[0];
	}

	/**
	 * The add tagged-record of a `$form['name'] = new Control()` value-control offset assignment,
	 * with the literal offset name, or null when the statement is not such an assignment. Lets a
	 * later separate-statement modifier reached through the offset (`$form['name']->setRequired()`)
	 * or its getComponent() equivalent attach to the slot the same way a bound variable does.
	 *
	 * @return array{record: array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}, slot: string}|null
	 */
	private function offsetControlBindingRecord(Node\Stmt $stmt, WalkContext $ctx): ?array
	{
		$trackedName = $ctx->getTrackedName();
		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();

		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return null;
		}

		$target = $stmt->expr->var;
		if (
			!$target instanceof ArrayDimFetch
			|| !$target->var instanceof Variable
			|| $target->var->name !== $trackedName
			|| !$target->dim instanceof StringLiteral
		) {
			return null;
		}

		$records = $this->taggedRecordsIn($stmt, $trackedName, $recordsByNode);
		if ($records === [] || $records[0]['node'] !== $stmt->expr) {
			return null;
		}

		$resolution = $this->summarizeRecord($records[0], $trackedName, $trackedClass)->getResolution();
		if ($resolution === null || $resolution->getKind() !== ControlValueResolution::KIND_VALUE) {
			return null;
		}

		return ['record' => $records[0], 'slot' => $target->dim->value];
	}

	/**
	 * For a method-call chain whose root receiver is the control variable or a known Rules
	 * variable, the condition depth its outermost call starts from (startDepth) and the depth
	 * its result expression evaluates to (resultDepth, used when the chain is bound to a new
	 * Rules variable). Null when the chain is not rooted at a tracked receiver.
	 *
	 * @param array<string, int> $rulesVars
	 * @return array{startDepth: int, resultDepth: int}|null
	 */
	private function chainRootDepth(Expr $expr, string $controlVar, array $rulesVars, string $trackedName): ?array
	{
		if (!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall) {
			return null;
		}

		$root = MethodChainSpine::rootReceiver($expr);

		if (strncmp($controlVar, "\0", 1) === 0) {
			if (!$this->chainRootsAtSlot($expr, $root, (string) substr($controlVar, 1), $trackedName)) {
				return null;
			}

			$startDepth = 0;
		} elseif (!$root instanceof Variable || !is_string($root->name)) {
			return null;
		} elseif ($root->name === $controlVar) {
			$startDepth = 0;
		} elseif (isset($rulesVars[$root->name])) {
			$startDepth = $rulesVars[$root->name];
		} else {
			return null;
		}

		$depth = $startDepth;
		foreach (array_reverse(MethodChainSpine::calls($expr)) as $call) {
			if (!$call->name instanceof Identifier) {
				continue;
			}

			$depth += MethodChainSpine::conditionTransition($call->name->toString());
		}

		return ['startDepth' => $startDepth, 'resultDepth' => $depth];
	}

	/**
	 * Whether a modifier chain reaches the form's slot $slot through one of the two access paths
	 * Nette routes to the same control: `$form['slot']->…` (offset, ArrayDimFetch root) or
	 * `$form->getComponent('slot')->…` (the tracked-form variable with getComponent as the bottom
	 * call). offsetGet delegates to getComponent, so both attach the modifier identically.
	 */
	private function chainRootsAtSlot(Expr $expr, Expr $root, string $slot, string $trackedName): bool
	{
		if (
			$root instanceof ArrayDimFetch
			&& $root->var instanceof Variable
			&& $root->var->name === $trackedName
			&& $root->dim instanceof StringLiteral
			&& $root->dim->value === $slot
		) {
			return true;
		}

		if (!$root instanceof Variable || $root->name !== $trackedName) {
			return false;
		}

		$calls = MethodChainSpine::calls($expr);
		$bottom = $calls[count($calls) - 1] ?? null;

		return $bottom instanceof MethodCall
			&& $bottom->name instanceof Identifier
			&& $bottom->name->toString() === 'getComponent'
			&& ($bottom->getArgs()[0]->value ?? null) instanceof StringLiteral
			&& $bottom->getArgs()[0]->value->value === $slot;
	}

	/**
	 * Whether a control-rooted chain passes the control (or a known Rules variable) as an
	 * argument to any of its calls — the receiver spine is excluded, so `$c->addRule(...)` is
	 * not a leak but `$c->addRule($c)` or `helper($c)` woven into the chain is.
	 *
	 * @param array<string, int> $rulesVars
	 */
	private function chainLeaksVariable(Expr $expr, string $controlVar, array $rulesVars): bool
	{
		foreach (MethodChainSpine::calls($expr) as $call) {
			if ($call->isFirstClassCallable()) {
				continue;
			}

			foreach ($call->getArgs() as $arg) {
				if ($this->referencesAnyVariable($arg, $controlVar, $rulesVars)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string, int> $rulesVars
	 */
	private function referencesAnyVariable(Node $node, string $controlVar, array $rulesVars): bool
	{
		foreach ((new NodeFinder())->findInstanceOf($node, Variable::class) as $variable) {
			if (!is_string($variable->name)) {
				continue;
			}

			if ($variable->name === $controlVar || isset($rulesVars[$variable->name])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{state: CompositionState, terminated: bool}
	 */
	private function branchExprResult(Expr $expr, CompositionState $pre, WalkContext $ctx): array
	{
		$trackedName = $ctx->getTrackedName();
		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();

		$conditionalIds = $this->conditionalNodeIds($expr);

		$state = $pre;
		foreach ($this->taggedRecordsIn($expr, $trackedName, $recordsByNode) as $rec) {
			$summary = $this->summarizeRecord($rec, $trackedName, $trackedClass);
			$presence = isset($conditionalIds[spl_object_id($rec['node'])]) ? Certainty::MAYBE : Certainty::HAPPENS;
			$state = $state->applySequential($summary, false, $presence);
		}

		return ['state' => $state, 'terminated' => false];
	}

	/**
	 * @param Certainty::* $baseCertainty
	 */
	private function applyTaggedIn(
		Node $haystack,
		CompositionState $state,
		WalkContext $ctx,
		string $baseCertainty = Certainty::HAPPENS
	): CompositionState
	{
		$trackedName = $ctx->getTrackedName();
		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();

		$conditionalIds = $this->conditionalNodeIds($haystack);

		foreach ($this->taggedRecordsIn($haystack, $trackedName, $recordsByNode) as $rec) {
			$summary = $this->summarizeRecord($rec, $trackedName, $trackedClass);
			$presence = isset($conditionalIds[spl_object_id($rec['node'])]) ? Certainty::MAYBE : $baseCertainty;
			$state = $state->applySequential($summary, false, $presence);
		}

		return $state;
	}

	/**
	 * Applies the component-affecting adds sitting in a compound statement's header expressions
	 * (an if/switch/loop condition or a for's init/loop) before its arms are walked. Headers are
	 * not part of the arm/body statements, so without this pass an add hidden in a condition
	 * (`if (($x = $form->addText('a')) !== null)`) or a loop head is silently dropped. Each header
	 * position carries the certainty its expression runs with: a header always evaluated at least
	 * once when the statement is reached is HAPPENS; a for-loop step (which runs only after a body
	 * iteration) and an elseif condition (evaluated only when the preceding conditions were false)
	 * are MAYBE. A nested conditional inside a header composes on top via conditionalNodeIds.
	 */
	private function applyStatementHeaders(
		Node\Stmt $stmt,
		CompositionState $state,
		WalkContext $ctx
	): CompositionState
	{
		foreach ($this->statementHeaderExprs($stmt) as $header) {
			$state = $this->applyTaggedIn(
				$header['expr'],
				$state,
				$ctx,
				$header['certainty'],
			);
		}

		return $state;
	}

	/**
	 * @return list<array{expr: Expr, certainty: Certainty::*}>
	 */
	private function statementHeaderExprs(Node\Stmt $stmt): array
	{
		if ($stmt instanceof If_) {
			$exprs = [['expr' => $stmt->cond, 'certainty' => Certainty::HAPPENS]];
			foreach ($stmt->elseifs as $elseif) {
				// An elseif condition runs only when every preceding condition was false, so its
				// add is genuinely conditional — MAYBE, never HAPPENS.
				$exprs[] = ['expr' => $elseif->cond, 'certainty' => Certainty::MAYBE];
			}

			return $exprs;
		}

		if ($stmt instanceof Switch_ || $stmt instanceof While_ || $stmt instanceof Do_) {
			return [['expr' => $stmt->cond, 'certainty' => Certainty::HAPPENS]];
		}

		if ($stmt instanceof Foreach_) {
			return [['expr' => $stmt->expr, 'certainty' => Certainty::HAPPENS]];
		}

		if ($stmt instanceof For_) {
			$exprs = [];
			foreach ($stmt->init as $expr) {
				$exprs[] = ['expr' => $expr, 'certainty' => Certainty::HAPPENS];
			}

			foreach ($stmt->cond as $expr) {
				$exprs[] = ['expr' => $expr, 'certainty' => Certainty::HAPPENS];
			}

			foreach ($stmt->loop as $expr) {
				$exprs[] = ['expr' => $expr, 'certainty' => Certainty::MAYBE];
			}

			return $exprs;
		}

		return [];
	}

	/**
	 * After a compound statement's branches join, a container-building variable bound inside an
	 * arm (`$s = $form->addContainer('s')`) may be mutated again in the rest of the enclosing
	 * block (`$s->addText('y')`). The arm-local walk never sees those later statements, and the
	 * variable may not even be defined on every path, so any such reference makes the container's
	 * accumulated shape unreliable and its inner shape is opened. Conservative: a reference is any
	 * occurrence of the name; the mutation itself is not interpreted.
	 *
	 * @param array<Node\Stmt> $followingStmts
	 */
	private function openPostArmContainers(
		Node\Stmt $compound,
		array $followingStmts,
		CompositionState $state,
		string $trackedName
	): CompositionState
	{
		if ($followingStmts === []) {
			return $state;
		}

		$armContainerVars = $this->armContainerVarBindings($this->compoundArmStmts($compound), $trackedName);
		if ($armContainerVars === []) {
			return $state;
		}

		$referenced = $this->referencedVariableNames($followingStmts);
		foreach ($armContainerVars as $varName => $slot) {
			if (isset($referenced[$varName])) {
				$state = $state->openContainerShape($slot);
			}
		}

		return $state;
	}

	/**
	 * The statements that make up a compound statement's arms (its branch/loop/handler bodies),
	 * flattened. Header expressions are excluded — a container bound in a header is not an
	 * arm-local binding.
	 *
	 * @return array<Node\Stmt>
	 */
	private function compoundArmStmts(Node\Stmt $compound): array
	{
		if ($compound instanceof If_) {
			$stmts = $compound->stmts;
			foreach ($compound->elseifs as $elseif) {
				$stmts = array_merge($stmts, $elseif->stmts);
			}

			return $compound->else === null ? $stmts : array_merge($stmts, $compound->else->stmts);
		}

		if ($compound instanceof Switch_) {
			$stmts = [];
			foreach ($compound->cases as $case) {
				$stmts = array_merge($stmts, $case->stmts);
			}

			return $stmts;
		}

		if ($compound instanceof TryCatch) {
			$stmts = $compound->stmts;
			foreach ($compound->catches as $catch) {
				$stmts = array_merge($stmts, $catch->stmts);
			}

			return $compound->finally === null ? $stmts : array_merge($stmts, $compound->finally->stmts);
		}

		if (
			$compound instanceof Foreach_
			|| $compound instanceof While_
			|| $compound instanceof Do_
			|| $compound instanceof For_
		) {
			return $compound->stmts;
		}

		return [];
	}

	/**
	 * Maps each variable bound to a container added directly on the tracked form inside the arm
	 * statements (`$x = $form->addContainer('x')`, including a chained spelling like
	 * `$x = $form->addContainer('x')->setDefaults([])`) to that container's slot name.
	 * Assignments nested in a closure are excluded (a different scope).
	 *
	 * @param array<Node\Stmt> $armStmts
	 * @return array<string, string>  container-bound variable name => slot name
	 */
	private function armContainerVarBindings(array $armStmts, string $trackedName): array
	{
		$closureInnerIds = ClosureScope::innerNodeIds($armStmts);
		$map = [];
		foreach ((new NodeFinder())->findInstanceOf($armStmts, Assign::class) as $assign) {
			if (isset($closureInnerIds[spl_object_id($assign)])) {
				continue;
			}

			if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
				continue;
			}

			$rhs = $assign->expr;
			if (!$rhs instanceof MethodCall && !$rhs instanceof NullsafeMethodCall) {
				continue;
			}

			$root = MethodChainSpine::rootReceiver($rhs);
			if (!$root instanceof Variable || $root->name !== $trackedName) {
				continue;
			}

			$chainCalls = MethodChainSpine::calls($rhs);
			$rootCall = end($chainCalls);
			if ($rootCall === false) {
				continue;
			}

			$slot = $this->literalFirstArgValue($rootCall);
			if ($slot !== null) {
				$map[$assign->var->name] = $slot;
			}
		}

		return $map;
	}

	/**
	 * @param MethodCall|NullsafeMethodCall $call
	 */
	private function literalFirstArgValue(Node $call): ?string
	{
		$first = $call->getArgs()[0]->value ?? null;

		return $first instanceof StringLiteral ? $first->value : null;
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 * @return array<string, true>
	 */
	private function referencedVariableNames(array $stmts): array
	{
		$names = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, Variable::class) as $var) {
			if (is_string($var->name)) {
				$names[$var->name] = true;
			}
		}

		return $names;
	}

	/**
	 * @return array<int, true> spl_object_id set of nodes reachable only when a runtime condition holds
	 */
	private function conditionalNodeIds(Node $root): array
	{
		$finder = new NodeFinder();
		$ids = [];

		foreach ($finder->findInstanceOf($root, Expr::class) as $expr) {
			if ($expr instanceof NullsafeMethodCall || $expr instanceof NullsafePropertyFetch) {
				$ids[spl_object_id($expr)] = true;
			}

			foreach ($this->conditionalBranches($expr) as $branch) {
				foreach ($finder->findInstanceOf($branch, Node::class) as $node) {
					$ids[spl_object_id($node)] = true;
				}
			}
		}

		return $ids;
	}

	/**
	 * @return list<Node>
	 */
	private function conditionalBranches(Expr $expr): array
	{
		if ($expr instanceof Ternary) {
			return $expr->if !== null ? [$expr->if, $expr->else] : [$expr->else];
		}

		if ($expr instanceof BooleanAnd || $expr instanceof BooleanOr || $expr instanceof Coalesce) {
			return [$expr->right];
		}

		if ($expr instanceof AssignCoalesce) {
			return [$expr->expr];
		}

		if ($expr instanceof Match_) {
			return array_values($expr->arms);
		}

		// a nullsafe receiver ($expr->var) always evaluates; only the call itself is guarded
		if ($expr instanceof NullsafeMethodCall) {
			$branches = [$expr->name];
			foreach ($expr->getArgs() as $arg) {
				$branches[] = $arg;
			}

			return $branches;
		}

		if ($expr instanceof NullsafePropertyFetch) {
			return [$expr->name];
		}

		return [];
	}

	/**
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 * @return list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}>
	 */
	private function taggedRecordsIn(Node $haystack, string $trackedName, array $recordsByNode): array
	{
		$finder = new NodeFinder();
		$found = $finder->find(
			$haystack,
			static fn (Node $n): bool => $n->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode,
		);

		$closureInternalIds = $this->byRefClosureInternalNodeIds($haystack, $trackedName, $finder)
			+ $this->rebindingClosureInternalNodeIds($haystack, $trackedName, $finder)
			+ $this->deferredCallbackNodeIds($haystack, $trackedName, $recordsByNode)
			+ $this->eventStoredCallbackIds;

		$records = [];
		foreach ($found as $node) {
			$id = spl_object_id($node);
			if (!isset($recordsByNode[$id])) {
				continue;
			}

			if (isset($closureInternalIds[$id])) {
				continue;
			}

			if (!$this->targetsTrackedVariable($node, $trackedName)) {
				continue;
			}

			$records[] = $recordsByNode[$id];
		}

		usort(
			$records,
			static fn (array $a, array $b): int => $a['node']->getStartFilePos() <=> $b['node']->getStartFilePos(),
		);

		return $records;
	}

	/**
	 * Node ids that live inside a closure capturing the tracked form by reference
	 * (use (&$trackedName)) lexically nested within the haystack. Such a closure's
	 * component-affecting ops only mutate the form when it actually runs, which is decided
	 * separately (processByRefClosures). Closures that take the form as a parameter, or
	 * capture it by value, are left to the normal scan so factory/handler bodies still
	 * resolve their own container shape.
	 *
	 * @return array<int, true>
	 */
	private function byRefClosureInternalNodeIds(Node $haystack, string $trackedName, NodeFinder $finder): array
	{
		$ids = [];
		foreach ($finder->find(
			$haystack,
			fn (Node $n): bool => $n instanceof Closure && $this->capturesByRef($n, $trackedName),
		) as $closure) {
			foreach ($finder->find($closure, static fn (Node $n): bool => true) as $inner) {
				if ($inner === $closure) {
					continue;
				}

				$ids[spl_object_id($inner)] = true;
			}
		}

		return $ids;
	}

	private function capturesByRef(Closure $closure, string $trackedName): bool
	{
		foreach ($closure->uses as $use) {
			if ($use->byRef && $use->var->name === $trackedName) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, true>
	 */
	private function rebindingClosureInternalNodeIds(Node $haystack, string $trackedName, NodeFinder $finder): array
	{
		$ids = [];
		foreach ($finder->find(
			$haystack,
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		) as $closure) {
			assert($closure instanceof Closure || $closure instanceof ArrowFunction);
			// A body that rebinds the tracked name owns its records: they target the inner
			// binding (walked when the body is absorbed), never this walk's object. The
			// by-value-capture variant, where the pre-rebind object stays reachable on some
			// paths, additionally opens the shape (valueCapturedRebindAmbiguity).
			$bodyStmts = $closure instanceof Closure ? $closure->stmts : [new Expression($closure->expr)];
			if (($this->countVariableBindings($bodyStmts, false)[$trackedName] ?? 0) === 0) {
				continue;
			}

			foreach ($finder->find($closure, static fn (Node $n): bool => true) as $inner) {
				if ($inner === $closure) {
					continue;
				}

				$ids[spl_object_id($inner)] = true;
			}
		}

		return $ids;
	}

	private function valueCapturedRebindAmbiguity(Node\Stmt $stmt, string $trackedName): bool
	{
		foreach ((new NodeFinder())->find(
			$stmt,
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		) as $closure) {
			assert($closure instanceof Closure || $closure instanceof ArrowFunction);
			if ($closure instanceof Closure) {
				$capturesByValue = false;
				foreach ($closure->uses as $use) {
					if (!$use->byRef && $use->var->name === $trackedName) {
						$capturesByValue = true;

						break;
					}
				}

				if (!$capturesByValue) {
					continue;
				}

				$bodyStmts = $closure->stmts;
			} else {
				// An arrow function captures implicitly by value unless a param shadows the name.
				$shadowed = false;
				foreach ($closure->getParams() as $param) {
					if ($param->var instanceof Variable && $param->var->name === $trackedName) {
						$shadowed = true;

						break;
					}
				}

				if ($shadowed) {
					continue;
				}

				$bodyStmts = [new Expression($closure->expr)];
			}

			if (($this->countVariableBindings($bodyStmts, false)[$trackedName] ?? 0) > 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, ControlBinding> $bindings
	 * @return array{state: CompositionState, bindings: array<string, ControlBinding>}
	 */
	private function joinConditional(
		If_ $stmt,
		CompositionState $pre,
		WalkContext $ctx,
		?ReturnPointCollector $returns,
		array $bindings
	): array
	{
		$arms = [];
		$arms[] = $this->armResult(
			$stmt->stmts,
			$pre,
			$ctx,
			$returns,
			$bindings,
		);

		foreach ($stmt->elseifs as $elseif) {
			$arms[] = $this->armResult(
				$elseif->stmts,
				$pre,
				$ctx,
				$returns,
				$bindings,
			);
		}

		$hasElse = $stmt->else !== null;
		if ($hasElse) {
			$arms[] = $this->armResult(
				$stmt->else->stmts,
				$pre,
				$ctx,
				$returns,
				$bindings,
			);
		}

		return [
			'state' => CompositionState::joinBranches($pre, $arms, !$hasElse),
			'bindings' => $this->joinBindings($bindings, $this->reachingBindings($arms), !$hasElse),
		];
	}

	/**
	 * @param array<string, ControlBinding> $bindings
	 * @return array{state: CompositionState, bindings: array<string, ControlBinding>}
	 */
	private function joinSwitch(
		Switch_ $stmt,
		CompositionState $pre,
		WalkContext $ctx,
		?ReturnPointCollector $returns,
		array $bindings
	): array
	{
		$arms = [];
		$hasDefault = false;
		$pending = [];
		foreach ($stmt->cases as $case) {
			if ($case->cond === null) {
				$hasDefault = true;
			}

			foreach ($case->stmts as $caseStmt) {
				$pending[] = $caseStmt;
			}

			if ($case->stmts === []) {
				continue;
			}

			$arms[] = $this->armResult(
				$pending,
				$pre,
				$ctx,
				$returns,
				$bindings,
			);
			$pending = [];
		}

		return [
			'state' => CompositionState::joinBranches($pre, $arms, !$hasDefault),
			'bindings' => $this->joinBindings($bindings, $this->reachingBindings($arms), !$hasDefault),
		];
	}

	/**
	 * @param array<string, ControlBinding> $bindings
	 * @return array{state: CompositionState, bindings: array<string, ControlBinding>}
	 */
	private function joinTryCatch(
		TryCatch $stmt,
		CompositionState $pre,
		WalkContext $ctx,
		?ReturnPointCollector $returns,
		array $bindings
	): array
	{
		$trackedName = $ctx->getTrackedName();

		$tryResult = $this->walkStmts(
			$stmt->stmts,
			$pre,
			$ctx,
			$returns,
			$bindings,
		);
		$arms = [['state' => $tryResult['state'], 'terminated' => false, 'bindings' => $tryResult['bindings']]];

		foreach ($stmt->catches as $catch) {
			$catchPre = $pre;
			$catchBindings = $bindings;
			// catch (E $form) rebinds the tracked name to the exception for the whole handler,
			// so its accumulated shape no longer describes $form there.
			if ($catch->var !== null && $catch->var->name === $trackedName) {
				$catchPre = CompositionState::initial()->withUnknownReason(UnknownReason::REBIND_UNPROVEN);
				$catchBindings = [];
			}

			$arms[] = $this->armResult(
				$catch->stmts,
				$catchPre,
				$ctx,
				$returns,
				$catchBindings,
			);
		}

		$state = CompositionState::joinBranches($pre, $arms, true);
		$bindings = $this->joinBindings($bindings, $this->reachingBindings($arms), true);

		if ($stmt->finally !== null) {
			$finallyResult = $this->walkStmts(
				$stmt->finally->stmts,
				$state,
				$ctx,
				$returns,
				$bindings,
			);
			$state = $finallyResult['state'];
			$bindings = $finallyResult['bindings'];
		}

		return ['state' => $state, 'bindings' => $bindings];
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 * @param array<string, ControlBinding> $bindings
	 * @return array{state: CompositionState, terminated: bool, bindings: array<string, ControlBinding>}
	 */
	private function armResult(
		array $stmts,
		CompositionState $pre,
		WalkContext $ctx,
		?ReturnPointCollector $returns,
		array $bindings
	): array
	{
		return $this->walkStmts($stmts, $pre, $ctx, $returns, $bindings);
	}

	/**
	 * The binding environments of the arms that fall through to the join point (a terminated
	 * arm — return/throw/break/continue — does not reach it, so its bindings are excluded
	 * exactly as joinBranches excludes its slots).
	 *
	 * @param list<array{state: CompositionState, terminated: bool, bindings: array<string, ControlBinding>}> $arms
	 * @return list<array<string, ControlBinding>>
	 */
	private function reachingBindings(array $arms): array
	{
		$reaching = [];
		foreach ($arms as $arm) {
			if (!$arm['terminated']) {
				$reaching[] = $arm['bindings'];
			}
		}

		return $reaching;
	}

	/**
	 * Joins the binding environment across branch arms beside the shape join: a control bound
	 * before the branch survives only when every reaching arm still holds it unchanged (same
	 * add-record, accumulated chains, Rules variables and escape flag); a control whose chains
	 * an arm extended or whose binding an arm dropped becomes escaped, so any further modifier
	 * opens its already-joined slot rather than re-folding a now path-dependent chain set.
	 * Bindings introduced only inside an arm do not survive the join.
	 *
	 * @param array<string, ControlBinding> $pre
	 * @param list<array<string, ControlBinding>> $armBindings
	 * @return array<string, ControlBinding>
	 */
	private function joinBindings(array $pre, array $armBindings, bool $hasImplicitFallThrough): array
	{
		$reaching = $armBindings;
		if ($hasImplicitFallThrough) {
			$reaching[] = $pre;
		}

		$result = [];
		foreach ($pre as $name => $binding) {
			$identical = true;
			foreach ($reaching as $map) {
				if (!isset($map[$name]) || !$this->bindingsIdentical($binding, $map[$name])) {
					$identical = false;

					break;
				}
			}

			$result[$name] = $identical ? $binding : $binding->escape();
		}

		return $result;
	}

	private function bindingsIdentical(ControlBinding $a, ControlBinding $b): bool
	{
		return $a->getRecord() === $b->getRecord()
			&& $a->isEscaped() === $b->isEscaped()
			&& $a->getRulesVars() === $b->getRulesVars()
			&& $a->getChains() === $b->getChains();
	}

	/**
	 * Classifies how a statement rebinds the tracked form variable, so the walk can drop the
	 * accumulated shape honestly. REBIND_CLEAN is a provably fresh `$form = new Form()` (its
	 * shape starts empty); REBIND_OPEN is any other reassignment — a factory/method result, a
	 * clone, a property/variable, a reference (`$form =& $x`), a non-literal destructure
	 * (`[$form] = $x`), a foreach value/key binding, or a rebind buried in a condition head or
	 * expression subtree — which repoints the name at an object the walk cannot account for, so
	 * the shape must open rather than silently reset clean. null means no rebind here.
	 *
	 * @return self::REBIND_*|null
	 */
	private function trackedRebindKind(Node\Stmt $stmt, string $trackedName): ?string
	{
		if ($stmt instanceof Expression) {
			$expr = $stmt->expr;

			if ($expr instanceof Assign && $expr->var instanceof Variable && $expr->var->name === $trackedName) {
				return $this->freshInstance($expr->expr) !== null ? self::REBIND_CLEAN : self::REBIND_OPEN;
			}

			if ($expr instanceof AssignRef && $expr->var instanceof Variable && $expr->var->name === $trackedName) {
				return self::REBIND_OPEN;
			}

			if (
				$expr instanceof Assign
				&& ($expr->var instanceof Array_ || $expr->var instanceof List_)
				&& !$expr->expr instanceof Array_
				&& $this->destructureBindsTracked($expr->var, $trackedName)
			) {
				return self::REBIND_OPEN;
			}
		}

		if ($stmt instanceof Foreach_ && $this->foreachBindsTracked($stmt, $trackedName)) {
			return self::REBIND_OPEN;
		}

		foreach ($this->rebindScanExprs($stmt) as $scanExpr) {
			if ($this->subtreeHasUnprovenRebind($scanExpr, $trackedName)) {
				return self::REBIND_OPEN;
			}
		}

		return null;
	}

	private function freshInstance(Expr $expr): ?New_
	{
		while ($expr instanceof Assign) {
			$expr = $expr->expr;
		}

		return $expr instanceof New_ ? $expr : null;
	}

	/**
	 * What the constructor of the class a REBIND_CLEAN names builds, folded into the state the rebind
	 * just reset.
	 *
	 * A fresh `new X()` was treated as an empty shape and nothing else, which is true only of a class
	 * whose constructor adds no controls. For every other class it PROVED absent whatever the
	 * constructor had just added, and it did so selectively: the same `new X()` written as a factory's
	 * `return new X()`, or reached as a createComponent* child, has always been shaped by
	 * ConstructedFormShapeResolver, so the answer depended on how the caller held the form rather than
	 * on what the class does. This is the same resolver, asked at the third spelling.
	 *
	 * A class it declines to shape — an unreadable constructor, a `new $class`, an anonymous class —
	 * OPENS the shape under CONSTRUCTOR_BUILD, the marker the factory arm's own resolution already
	 * carries for a constructor it could not read. An empty CLOSED shape is kept only where the
	 * resolver proves one: a class with no constructor at all, and a bare Nette base construction.
	 */
	private function absorbConstruction(CompositionState $state, ?New_ $new): CompositionState
	{
		if ($new === null || !$new->class instanceof Node\Name) {
			return $state->withUnknownReason(UnknownReason::CONSTRUCTOR_BUILD);
		}

		$shape = $this->constructedResolver()->resolve($new->class->toString(), count($new->getArgs()));

		return $shape === null
			? $state->withUnknownReason(UnknownReason::CONSTRUCTOR_BUILD)
			: $state->absorbCalleeContribution($shape);
	}

	/**
	 * The `new` a REBIND_CLEAN statement binds the tracked name to, through the same nested-assignment
	 * unwrapping the classification itself does, so the node absorbConstruction() shapes is the one the
	 * classification called fresh. Null for a statement that binds no readable construction.
	 */
	private function cleanRebindConstruction(Node\Stmt $stmt, string $trackedName): ?New_
	{
		if (
			!$stmt instanceof Expression
			|| !$stmt->expr instanceof Assign
			|| !$stmt->expr->var instanceof Variable
			|| $stmt->expr->var->name !== $trackedName
		) {
			return null;
		}

		return $this->freshInstance($stmt->expr->expr);
	}

	/**
	 * @param Array_|List_ $target
	 */
	private function destructureBindsTracked(Expr $target, string $trackedName): bool
	{
		foreach ($target->items as $item) {
			if ($item === null) {
				continue;
			}

			$value = $item->value;
			if ($value instanceof Variable && $value->name === $trackedName) {
				return true;
			}

			if (
				(
					$value instanceof Array_
					|| $value instanceof List_
				)
				&& $this->destructureBindsTracked($value, $trackedName)
			) {
				return true;
			}
		}

		return false;
	}

	private function foreachBindsTracked(Foreach_ $stmt, string $trackedName): bool
	{
		foreach ([$stmt->valueVar, $stmt->keyVar] as $target) {
			if ($target instanceof Variable && $target->name === $trackedName) {
				return true;
			}

			if (
				(
					$target instanceof Array_
					|| $target instanceof List_
				)
				&& $this->destructureBindsTracked($target, $trackedName)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The sub-expressions of a statement the structural walk does not otherwise reset for: an
	 * assignment RHS, a condition head, a loop head, a returned expression. A rebind hiding in
	 * one of these (`$x = ($form = build())`, `while ($form = $it->next())`) is missed by both
	 * the top-level checks and the body recursion.
	 *
	 * @return list<Expr>
	 */
	private function rebindScanExprs(Node\Stmt $stmt): array
	{
		if ($stmt instanceof Expression) {
			$expr = $stmt->expr;
			if ($expr instanceof Assign || $expr instanceof AssignRef || $expr instanceof AssignOp) {
				return [$expr->expr];
			}

			return [$expr];
		}

		if ($stmt instanceof If_ || $stmt instanceof While_ || $stmt instanceof Do_ || $stmt instanceof Switch_) {
			return [$stmt->cond];
		}

		if ($stmt instanceof For_) {
			return array_values(array_merge($stmt->init, $stmt->cond, $stmt->loop));
		}

		if ($stmt instanceof Foreach_) {
			return [$stmt->expr];
		}

		if ($stmt instanceof Return_ && $stmt->expr !== null) {
			return [$stmt->expr];
		}

		return [];
	}

	private function subtreeHasUnprovenRebind(Expr $expr, string $trackedName): bool
	{
		$finder = new NodeFinder();

		// A closure/arrow-fn body is an isolated scope: a reassignment inside it cannot rebind
		// the outer name, so exclude every node nested under one from the scan.
		$closureInnerIds = ClosureScope::innerNodeIds([$expr]);

		foreach ($finder->find(
			[$expr],
			static fn (Node $n): bool => $n instanceof Assign || $n instanceof AssignRef || $n instanceof AssignOp,
		) as $assign) {
			if (!$assign instanceof Assign && !$assign instanceof AssignRef && !$assign instanceof AssignOp) {
				continue;
			}

			if (isset($closureInnerIds[spl_object_id($assign)])) {
				continue;
			}

			if (!$this->targetBindsTracked($assign->var, $trackedName)) {
				continue;
			}

			if ($assign instanceof Assign && $this->freshInstance($assign->expr) !== null) {
				continue;
			}

			return true;
		}

		return false;
	}

	private function targetBindsTracked(Expr $target, string $trackedName): bool
	{
		if ($target instanceof Variable && $target->name === $trackedName) {
			return true;
		}

		return ($target instanceof Array_ || $target instanceof List_)
			&& $this->destructureBindsTracked($target, $trackedName);
	}

	/**
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 */
	private function isSelfRootedAddReassignment(Node\Stmt $stmt, string $trackedName, array $recordsByNode): bool
	{
		if (
			!$stmt instanceof Expression
			|| !$stmt->expr instanceof Assign
			|| !$stmt->expr->var instanceof Variable
			|| $stmt->expr->var->name !== $trackedName
		) {
			return false;
		}

		$rhs = $stmt->expr->expr;
		if (!$rhs instanceof MethodCall && !$rhs instanceof NullsafeMethodCall) {
			return false;
		}

		$root = MethodChainSpine::rootReceiver($rhs);
		if (!$root instanceof Variable || $root->name !== $trackedName) {
			return false;
		}

		return $this->taggedRecordsIn($stmt, $trackedName, $recordsByNode) !== [];
	}

	/**
	 * Variables that alias the tracked form object after this statement. Covers a bare
	 * alias ($x = $form), a chained assignment ($a = $b = new Form() / $a = $b = $form —
	 * every other chain variable aliases) and an array destructuring of the form
	 * ([$x] = [$form] / list($x) = [$form]). A subsequent component-affecting op on any of
	 * these names opens the shape via FORM_ALIASED. Top-level scan only; no closure descent.
	 *
	 * @return list<string>
	 */
	private function aliasTargetsOf(Node\Stmt $stmt, string $trackedName): array
	{
		if (!$stmt instanceof Expression) {
			return [];
		}

		$expr = $stmt->expr;
		if ($expr instanceof Assign && ($expr->var instanceof Array_ || $expr->var instanceof List_)) {
			return $this->destructureAliasTargets($expr->var, $expr->expr, $trackedName);
		}

		if (!$expr instanceof Assign && !$expr instanceof AssignRef) {
			return [];
		}

		$chainVars = [];
		$cursor = $expr;
		while ($cursor instanceof Assign || $cursor instanceof AssignRef) {
			if ($cursor->var instanceof Variable && is_string($cursor->var->name)) {
				$chainVars[] = $cursor->var->name;
			}

			$cursor = $cursor->expr;
		}

		$rootIsTracked = $cursor instanceof Variable && $cursor->name === $trackedName;
		$trackedInChain = in_array($trackedName, $chainVars, true);
		if (!$rootIsTracked && !$trackedInChain) {
			return [];
		}

		$aliases = [];
		foreach ($chainVars as $name) {
			if ($name !== $trackedName) {
				$aliases[] = $name;
			}
		}

		return $aliases;
	}

	/**
	 * @param Array_|List_ $target
	 * @return list<string>
	 */
	private function destructureAliasTargets(Expr $target, Expr $source, string $trackedName): array
	{
		if (!$source instanceof Array_) {
			return [];
		}

		$sourceKeys = [];
		foreach ($source->items as $position => $item) {
			if (
				$item->value instanceof Variable
				&& $item->value->name === $trackedName
			) {
				$sourceKeys[$this->destructureKey($item, $position)] = true;
			}
		}

		if ($sourceKeys === []) {
			return [];
		}

		$aliases = [];
		foreach ($target->items as $position => $item) {
			if (
				$item !== null
				&& $item->value instanceof Variable
				&& is_string($item->value->name)
				&& isset($sourceKeys[$this->destructureKey($item, $position)])
			) {
				$aliases[] = $item->value->name;
			}
		}

		return $aliases;
	}

	/**
	 * @param int|string $position
	 */
	private function destructureKey(ArrayItem $item, $position): string
	{
		if ($item->key instanceof Node\Scalar\String_) {
			return 's:' . $item->key->value;
		}

		if ($item->key instanceof Node\Scalar\Int_) {
			return 'i:' . $item->key->value;
		}

		return 'p:' . $position;
	}

	private function callsDynamicMethodOnTracked(Node\Stmt $stmt, string $trackedName): bool
	{
		if (!$stmt instanceof Expression) {
			return false;
		}

		$sawDynamicName = false;
		foreach (MethodChainSpine::calls($stmt->expr) as $call) {
			if (!$call->name instanceof Identifier) {
				$sawDynamicName = true;
			}
		}

		$root = MethodChainSpine::rootReceiver($stmt->expr);

		return $sawDynamicName
			&& $root instanceof Variable
			&& $root->name === $trackedName;
	}

	/**
	 * A whole-container disabler called directly on the tracked form ($form->setDisabled()):
	 * a @form-disabler method that omits every descendant control from getValues(). A chained
	 * call ($form->addText('x')->setDisabled()) is a per-control modifier, not this.
	 */
	private function callsFormDisablerOnTracked(Node\Stmt $stmt, string $trackedName, string $trackedClass): bool
	{
		if (!$stmt instanceof Expression || $trackedClass === '' || strpos($trackedClass, '|') !== false) {
			return false;
		}

		$expr = $stmt->expr;

		return ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
			&& !$expr->isFirstClassCallable()
			&& $expr->var instanceof Variable
			&& $expr->var->name === $trackedName
			&& $expr->name instanceof Identifier
			&& $this->factory->isFormDisabler($trackedClass, $expr->name->toString());
	}

	/**
	 * A method that DECLARES what it registers, called on the tracked form under a name the
	 * component-affecting pass does not mark — `$form->attachThing('x')` where attachThing() carries
	 * an adds tag.
	 *
	 * That pass gates on the name alone, deliberately (its node ids are the walk's cache key, so they
	 * may not move with a type), which leaves the declaration with no tagged node to be resolved
	 * against. What the declaration still proves is that a component was added, so the shape opens
	 * rather than closing over a component the author took the trouble to declare. Only the names the
	 * pass never saw reach this: an annotated add* is tagged and resolves properly, name and class
	 * both.
	 */
	private function callsDeclaredAdderOnTracked(Node\Stmt $stmt, string $trackedName, string $trackedClass): bool
	{
		if (!$stmt instanceof Expression || $trackedClass === '' || strpos($trackedClass, '|') !== false) {
			return false;
		}

		$expr = $stmt->expr;
		if (
			(!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall)
			|| !$expr->var instanceof Variable
			|| $expr->var->name !== $trackedName
			|| !$expr->name instanceof Identifier
		) {
			return false;
		}

		$method = $expr->name->toString();

		return !ComponentAffectingNodeVisitor::tagsMethodName($method)
			&& $this->factory->declaresAdds($trackedClass, $method);
	}

	/**
	 * A statement calling a method that does not exist on the tracked form/container class
	 * ($form->someExtensionMethod()) — only reachable through Nette's runtime extensionMethod()
	 * registration, which can add components we cannot see, so the shape opens. A magic add*()
	 * already opens through the component-adding path; this catches every other magic call.
	 */
	private function callsUnknownMethodOnTracked(Node\Stmt $stmt, string $trackedName, string $trackedClass): bool
	{
		if (!$stmt instanceof Expression || $trackedClass === '' || strpos($trackedClass, '|') !== false) {
			return false;
		}

		$expr = $stmt->expr;
		if (
			(!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall)
			|| !$expr->var instanceof Variable
			|| $expr->var->name !== $trackedName
			|| !$expr->name instanceof Identifier
		) {
			return false;
		}

		$method = $expr->name->toString();
		if (strncmp($method, 'add', 3) === 0) {
			return false;
		}

		$reflection = $this->factory->getReflectionProvider();

		return $reflection->hasClass($trackedClass)
			&& !$reflection->getClass($trackedClass)->hasMethod($method);
	}

	/**
	 * @param array<string, true> $aliasNames
	 */
	private function mutatesAliasName(Node\Stmt $stmt, array $aliasNames): bool
	{
		if ($stmt instanceof Unset_) {
			foreach ($stmt->vars as $var) {
				if (
					$var instanceof ArrayDimFetch
					&& $var->var instanceof Variable
					&& is_string($var->var->name)
					&& isset($aliasNames[$var->var->name])
				) {
					return true;
				}
			}

			return false;
		}

		if (!$stmt instanceof Expression) {
			return false;
		}

		$expr = $stmt->expr;

		if (
			$expr instanceof MethodCall
			&& $expr->var instanceof Variable
			&& is_string($expr->var->name)
			&& isset($aliasNames[$expr->var->name])
			&& $expr->name instanceof Identifier
			&& $this->isComponentMutatingMethod($expr->name->toString())
		) {
			return true;
		}

		return $expr instanceof Assign
			&& $expr->var instanceof ArrayDimFetch
			&& $expr->var->var instanceof Variable
			&& is_string($expr->var->var->name)
			&& isset($aliasNames[$expr->var->var->name]);
	}

	private function isComponentMutatingMethod(string $method): bool
	{
		return strpos($method, 'add') === 0
			|| $method === 'removeComponent';
	}

	/**
	 * Whether `$child = $form[offset]` — or its `$child = $form->getComponent(offset)` equivalent,
	 * offsetGet delegating to getComponent — hands out a handle the form could gain fields through.
	 * It usually can, and the shape then opens: its known fields stay precise, but unseen ones are no
	 * longer reported as missing. An open shape reports NO absence at all, so this is the whole form's
	 * `orisaiNette.forms.noSuchComponent` coverage, not a widened member type.
	 *
	 * What the read costs is therefore decided by what the handle IS and what is DONE with it, never
	 * by the assignment's presence:
	 *
	 *     $form->addText('a')->setDisabled();          // closed, and always has been
	 *     $probe = $form['a']; $probe->setDisabled();  // the same component set, so also closed
	 *
	 * Two things have to hold, and both are proofs rather than absences of evidence. The child has to
	 * be one this state resolves to a SINGLE CLASS — which is what drops the containers and replicators
	 * out, since a child whose own shape the form carries resolves to none. And every use of the local
	 * has to be one ComponentHandleUses recognises as registering nothing.
	 *
	 * The second condition used to be paired with a third, that the resolved class be no
	 * `Nette\Forms\Container` — a hardcoded pair of class names standing in for the use predicate's one
	 * hole: the registering-name authority calls `getComponent()` inert, correctly for a name that
	 * describes a READ, while the container method of that name creates and attaches what it does not
	 * find. That hole is closed inside the use predicate now, which is where it can be closed for every
	 * caller rather than for the one that remembered to ask about the class.
	 *
	 * The residual trust is exactly the trust the chained spelling above has always had: that a
	 * control's setter does not reach back through getParent() to register on the form. Nothing else
	 * is assumed — a name this state does not hold, a non-literal offset, a handle whose uses are not
	 * all recognised, an assignment that is not a statement of its own, and the tracked variable being
	 * rebound onto its own child all keep opening, which is where every one of them started.
	 */
	private function pulledChildCanGainFields(Node\Stmt $stmt, CompositionState $state, WalkContext $ctx): bool
	{
		$reads = ComponentHandleUses::reads([$stmt], $ctx->getTrackedName());
		if ($reads === []) {
			return false;
		}

		// One read, and the assignment is the whole statement: anything else — two handles at once, a
		// read nested in a call or a chain (`($probe = $form['a'])->addText('b')`) — leaves the handle
		// reachable from an expression the use scan does not account for.
		return count($reads) !== 1
			|| !$stmt instanceof Expression
			|| $stmt->expr !== $reads[0]['assign']
			|| !$this->pulledChildIsInert($reads[0], $state, $ctx);
	}

	/**
	 * @param array{assign: Assign, handle: string, childName: string|null} $read
	 */
	private function pulledChildIsInert(array $read, CompositionState $state, WalkContext $ctx): bool
	{
		$childName = $read['childName'];
		if ($childName === null || $read['handle'] === $ctx->getTrackedName()) {
			return false;
		}

		// A name this state resolves to a single class, which is where the containers and replicators
		// channels drop out: resolvedChildClass() answers null for both, so a handle on a child whose
		// OWN shape the form carries never reaches the use question at all. That is a different fact
		// from the one the class used to be tested for, and it is the load-bearing one.
		return $state->resolvedChildClass($childName) !== null
			&& ComponentHandleUses::everyUseIsInert(
				$ctx->getRootStmts(),
				$read['handle'],
				$ctx->getTrackedName(),
			);
	}

	private function isContainerRegistration(Node $call, string $trackedName): bool
	{
		if (
			(!$call instanceof MethodCall && !$call instanceof NullsafeMethodCall)
			|| !$call->name instanceof Identifier
			|| $call->name->toString() !== 'addComponent'
		) {
			return false;
		}

		$arg0 = $call->getArgs()[0]->value ?? null;

		return $arg0 instanceof Variable && $arg0->name === $trackedName;
	}

	/**
	 * Every call in the statement that hands the tracked form straight to a callee — the sites that
	 * either resolve into a contribution or leave the shape open.
	 *
	 * @return list<array{call: CallLike, index: int|null}>
	 */
	private function trackedArgumentCalls(Node\Stmt $stmt, string $trackedName): array
	{
		$finder = new NodeFinder();

		$nestedCallIds = [];
		foreach ($finder->find(
			$stmt,
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		) as $closure) {
			foreach ($finder->find(
				$closure,
				static fn (Node $n): bool => $n instanceof MethodCall
					|| $n instanceof StaticCall
					|| $n instanceof FuncCall
					|| $n instanceof New_,
			) as $nestedCall) {
				$nestedCallIds[spl_object_id($nestedCall)] = true;
			}
		}

		return VariableEscapeDetector::directArgumentCalls(
			$stmt,
			$trackedName,
			fn (CallLike $call): bool => isset($nestedCallIds[spl_object_id($call)])
				|| $this->isAnalysisSentinel($call)
				|| $this->isContainerRegistration($call, $trackedName),
		);
	}

	/**
	 * What a followed callee adds to the tracked form, or null when the call cannot be followed and
	 * the shape must open instead.
	 *
	 * The contribution is a property of the CALLEE alone — it is computed against the callee's own
	 * parameter, cached under the callee's file and function-like, and folded into whichever
	 * caller's state is walking. Two forms routed through one helper therefore each get the same
	 * contribution merged into their own separate state; neither can acquire the other's fields.
	 *
	 * An EMPTY contribution is ACCEPTED, and it is the whole point: "this callee adds nothing" is a
	 * proof, and refusing to draw it leaves a form open on a helper that only reads or retypes what
	 * the builder already declared. What made it unsafe was that a body nobody could read produces
	 * the same empty answer; CalleeShapeResolver::bodyIsFullyVisible() now separates the two from the
	 * source, so an unreadable callee never reaches here and the empty answer means what it says.
	 *
	 * A callee that SUBTRACTS is still refused. The contribution is folded in additively — merging
	 * names in is all absorbCalleeContribution can express — so a removal has no representation, and
	 * a shape closed over one would keep claiming a component that is gone by render time.
	 */
	private function calleeContribution(CallLike $call, int $argIndex, WalkContext $ctx): ?FormShape
	{
		if ($call instanceof New_) {
			return null;
		}

		$resolved = $this->callees->resolveContainerParam($call, $argIndex, $this->walkOwnerClass);
		if ($resolved === null) {
			return null;
		}

		$key = $resolved['file'] . ':' . $resolved['method']->getStartLine() . '#' . $resolved['paramName'];
		if (isset($this->activeCallees[$key])) {
			return null;
		}

		if ($this->calleeSubtracts($resolved['method'], $resolved['paramName'], $resolved['paramClass'])) {
			return null;
		}

		// A fresh analyzer: the nested walk owns per-walk state (literal-name environment, owner
		// class, absorbed-closure flags) that the running walk still needs when it resumes.
		$nested = new self($this->factory, $this->cache, $this->callees);
		$nested->activeCallees = $this->activeCallees;
		$nested->activeCallees[$key] = true;

		$contribution = $nested->analyzeContainerParam(
			$resolved['paramName'],
			$resolved['method'],
			$resolved['paramClass'],
			(new EnclosingFunctionLikeLocator())->taggedRecords($resolved['method'], $ctx->getScope()),
			$ctx->getScope(),
			$resolved['file'],
		);

		if ($contribution->getMappedType() !== null) {
			return null;
		}

		return $contribution;
	}

	/**
	 * Folds in what a build method CALLED ON the tracked form registers — `$form->buildEverything()`,
	 * where buildEverything() is declared on the form and adds controls to `$this`.
	 *
	 * Such a call used to fold in nothing AND leave the shape closed, which is the worst of the two
	 * available answers: every control the method registered was proven absent. The sibling spellings
	 * were both already resolved — a build method handed the form as an ARGUMENT descends through
	 * calleeContribution(), and one called from the form's own CONSTRUCTOR is read by
	 * ConstructorFormShapeResolver — so only the receiver spelling closed over its own controls.
	 *
	 * THE BOUND, and it is the whole safety argument: a call reaches this only when the callee's own
	 * body registers on `$this`, read syntactically by the same ContainerRegistrationDetector the
	 * analysed/vendor split is decided from. A body that registers nothing (every setter, every
	 * accessor) and a body that cannot be read at all both leave the state EXACTLY as they found it,
	 * so nothing about absence reporting moves for them. This is deliberately not "any method call on
	 * the tracked form opens the shape": that would delete absence reporting wholesale on the strength
	 * of ignorance, where this only ever acts on a registration the callee's body states.
	 *
	 * The one body that registers and must still not be followed is Nette's own
	 * `Container::getComponent()` — it creates and attaches inside an `if` guarding a name that is not
	 * there yet, and it is the READ the absence rule exists to judge, so following it turned two
	 * correct `$form->getComponent('nope')` reports into "may not exist". That used to be spelled as a
	 * demand that every path through the callee reach a registration, which excluded it for the wrong
	 * reason and excluded honest conditional builders with it. ContainerLazyRead now answers per CALL
	 * whether that read attaches anything, off the two conditions the vendor body states, so the
	 * exception is derived and a builder whose registrations are all conditional is descended into and
	 * comes back with MAYBE presences.
	 *
	 * Within the bound the descent is preferred to opening: the nested walk resolves the names and
	 * classes exactly when it can, and degrades OPEN on its own terms when it cannot — including for
	 * a method the walk is already inside (recursion) and one that subtracts, both of which
	 * calleeContribution() refuses for the same reasons.
	 */
	private function applyRegisteringMethodOnTracked(
		Node\Stmt $stmt,
		CompositionState $state,
		WalkContext $ctx
	): CompositionState
	{
		$located = $this->registeringMethodOnTracked($stmt, $state, $ctx->getTrackedName(), $ctx->getTrackedClass());
		if ($located === null) {
			return $state;
		}

		$contribution = $this->registeringMethodContribution($located[0], $located[1], $ctx);

		return $contribution === null
			? $state->withUnknownReason(UnknownReason::UNFOLLOWED_CALL)
			: $state->absorbCalleeContribution($contribution);
	}

	/**
	 * The declaration behind `$form->m(...)` when m is one this walk may descend into: a bare call on
	 * the tracked variable, spelled with a name the component-affecting pass does NOT tag (an add* is
	 * already resolved by the tagged-record scan, and folding it a second time would double-count),
	 * declared on the tracked class, readable, registering something, and not a lazy read this state
	 * proves attaches nothing.
	 *
	 * @return array{ClassMethod, string}|null
	 */
	private function registeringMethodOnTracked(
		Node\Stmt $stmt,
		CompositionState $state,
		string $trackedName,
		string $trackedClass
	): ?array
	{
		if (!$stmt instanceof Expression || $trackedClass === '' || strpos($trackedClass, '|') !== false) {
			return null;
		}

		$expr = $stmt->expr;
		if (
			(!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall)
			|| $expr->isFirstClassCallable()
			|| !$expr->var instanceof Variable
			|| $expr->var->name !== $trackedName
			|| !$expr->name instanceof Identifier
		) {
			return null;
		}

		$method = $expr->name->toString();
		if (ComponentAffectingNodeVisitor::tagsMethodName($method)) {
			return null;
		}

		$located = $this->callees->visibleMethodNodeOf($trackedClass, $method);
		if ($located === null) {
			return null;
		}

		[$node, $file] = $located;

		// Memoized per declaration, not per class::method: the answer is a property of the body, and
		// the body is what the file and line name. visibleMethodNodeOf() above is still asked every
		// time, since it is what records the declaring file as a dependency of the walk's own entry.
		$key = $file . ':' . $node->getStartLine();
		$registers = $this->registersOnThis[$key] ??= ContainerRegistrationDetector::registersAnything(
			(new ContainerRegistrationDetector())->registrations(
				$node->stmts,
				ContainerRegistrationDetector::parameterIndexes($node),
			),
		);

		if (!$registers || $this->lazyReadAttachesNothing($expr, $method, $state, $trackedClass)) {
			return null;
		}

		return $located;
	}

	/**
	 * Whether this particular call is a `Container::getComponent()` the state PROVES attaches nothing,
	 * and therefore one whose body — which does register, inside the `if` guarding a name that is not
	 * there yet — describes something this call cannot do.
	 *
	 * Both inputs are already the walk's own: the child's presence is read through the one authority
	 * on it, and the factory question is the receiver class's own declaration. A name the call does not
	 * spell literally, a class reflection cannot resolve, and a presence this state is unsure of all
	 * answer false — the call is then descended into, and the descent degrades the shape OPEN on its
	 * own terms, which is the honest answer for a read that may create.
	 *
	 * @param MethodCall|NullsafeMethodCall $expr
	 */
	private function lazyReadAttachesNothing(
		Expr $expr,
		string $method,
		CompositionState $state,
		string $trackedClass
	): bool
	{
		if (!ContainerLazyRead::isLazyRead($method)) {
			return false;
		}

		$arg = $expr->getArgs()[0] ?? null;

		// The child the RECEIVER can gain is the first segment: Nette explodes a '-'-joined name, runs
		// the lazy create-and-attach for that segment alone, and delegates the rest to the child it
		// found. Splitting is ComponentPath's, so it happens here rather than inside the fact.
		$childName = $arg !== null && !$arg->unpack && $arg->value instanceof StringLiteral
			? ComponentPath::split($arg->value->value)[0]
			: null;

		return ContainerLazyRead::attachesChild(
			$childName,
			$childName === null ? Certainty::UNKNOWN : $this->childPresence($state, $childName),
			$childName !== null && $this->declaresComponentFactory($trackedClass, $childName),
		) === Certainty::NEVER;
	}

	/**
	 * What this state says about the container holding $childName, in the vocabulary
	 * ContainerLazyRead asks for. The answer itself is ComponentPath::childPresence()'s — the sole
	 * authority on definite presence, and on the closed-and-absent proof FormShapeUnknownAccessRule
	 * reports absence from — so a call this refuses to descend into can never be one that would have
	 * saved a report the rule then makes. All this adds is the state's own shape.
	 */
	private function childPresence(CompositionState $state, string $childName): string
	{
		return ComponentPath::childPresence($state->toFormShape(null), $childName);
	}

	/**
	 * Whether the receiver declares Nette's factory for the name. A class reflection cannot resolve
	 * answers TRUE: not disproving a factory is not proving one absent, and the true answer is the one
	 * that keeps the call descendable rather than the one that proves it inert.
	 *
	 * Every ancestor's file is recorded, not just the leaf's: the answer flips when a factory is added
	 * anywhere in the hierarchy, and the walk entry has to be recomputed when that file changes.
	 */
	private function declaresComponentFactory(string $trackedClass, string $childName): bool
	{
		$factory = ContainerLazyRead::factoryMethodFor($childName);
		if ($factory === null) {
			return false;
		}

		$provider = $this->factory->getReflectionProvider();
		$className = ltrim($trackedClass, '\\');
		if (!$provider->hasClass($className)) {
			return true;
		}

		$classReflection = $provider->getClass($className);
		foreach ($classReflection->getAncestors() as $ancestor) {
			$file = $ancestor->getFileName();
			if ($file !== null) {
				$this->cache->recorder()->record($file);
			}
		}

		return $classReflection->hasNativeMethod($factory);
	}

	private function registeringMethodContribution(ClassMethod $node, string $file, WalkContext $ctx): ?FormShape
	{
		$key = $file . ':' . $node->getStartLine() . '#this';
		if (isset($this->activeCallees[$key])) {
			return null;
		}

		// The class the nested walk is told it is shaping is the one the FILE declares, never the
		// receiver's: the contribution is cached under the callee's file and function-like, so a value
		// that varied with the caller would let one caller's entry answer another's question.
		$ownerClass = $this->callees->enclosingClassName($file, $node) ?? $ctx->getTrackedClass();

		if ($this->calleeSubtracts($node, 'this', $ownerClass)) {
			return null;
		}

		$nested = new self($this->factory, $this->cache, $this->callees);
		$nested->activeCallees = $this->activeCallees;
		$nested->activeCallees[$key] = true;

		$contribution = $nested->analyzeContainerParam(
			'this',
			$node,
			$ownerClass,
			(new EnclosingFunctionLikeLocator())->taggedRecords($node, $ctx->getScope()),
			$ctx->getScope(),
			$file,
		);

		return $contribution->getMappedType() !== null ? null : $contribution;
	}

	/**
	 * Whether the callee takes components AWAY from the container it is handed.
	 *
	 * Read off the callee's own AST rather than off its contribution, because a subtraction leaves no
	 * trace there: the walk applies a removal against the contribution's own names, and the names a
	 * helper removes are the CALLER's, so a helper that only removes computes an empty shape that is
	 * indistinguishable from one that does nothing at all.
	 *
	 * `removeComponent`/`offsetUnset`/`unset()` are matched through offset hops off the parameter,
	 * since they subtract whatever the receiver resolves to. A form disabler is matched on the
	 * parameter itself only, which is the same reach the walk models for a builder's own body — a
	 * disabler on a nested container is unmodelled there too. Matching is deliberately syntactic and
	 * over-eager: a wrong match only leaves the call unfollowed, which is where it started.
	 */
	private function calleeSubtracts(ClassMethod $method, string $paramName, string $paramClass): bool
	{
		$finder = new NodeFinder();

		foreach ($finder->findInstanceOf($method->stmts ?? [], Unset_::class) as $unset) {
			foreach ($unset->vars as $var) {
				if (self::rootsAtVariable($var, $paramName)) {
					return true;
				}
			}
		}

		$calls = array_merge(
			$finder->findInstanceOf($method->stmts ?? [], MethodCall::class),
			$finder->findInstanceOf($method->stmts ?? [], NullsafeMethodCall::class),
		);

		foreach ($calls as $node) {
			if (!$node->name instanceof Identifier) {
				continue;
			}

			$name = $node->name->toString();
			if (
				($name === 'removeComponent' || $name === 'offsetUnset')
				&& self::rootsAtVariable($node->var, $paramName)
			) {
				return true;
			}

			if (
				$node->var instanceof Variable
				&& $node->var->name === $paramName
				&& $this->factory->isFormDisabler($paramClass, $name)
			) {
				return true;
			}
		}

		return false;
	}

	private static function rootsAtVariable(Node $expr, string $name): bool
	{
		while ($expr instanceof ArrayDimFetch) {
			$expr = $expr->var;
		}

		return $expr instanceof Variable && $expr->name === $name;
	}

	/**
	 * Opens the shape when a form-mutating callable is handed to a parameter PHPStan's own
	 * invocation-timing trinary answers MAYBE for. The three answers are not symmetric: a
	 * proven-immediate callable's adds are already folded in by the ordinary record scan, a
	 * proven-later one's are excluded from the render-time shape (deferredCallbackNodeIds) and
	 * claiming their absence is right, but an unknown one may or may not have run — and picking
	 * either side there is a claim the walk cannot support. Absence is the dangerous side: it is
	 * what turns into a report.
	 *
	 * A callable STORED on an event property reaches the same MAYBE by a different road, since there
	 * is no parameter for the trinary to read: see storesDeferredFormCallback().
	 */
	private function applyCallbackTiming(
		Node\Stmt $stmt,
		CompositionState $state,
		WalkContext $ctx
	): CompositionState
	{
		foreach ($this->mutatingCallbackArguments($stmt, $ctx->getTrackedName(), $ctx->getRecordsByNode()) as $entry) {
			if (!$this->callees->parameterImmediacy($entry['call'], $entry['index'], $this->walkOwnerClass)->maybe()) {
				continue;
			}

			$state = $state->withUnknownReason(UnknownReason::CALLBACK_TIMING);
		}

		if ($this->storesDeferredFormCallback($stmt, $ctx)) {
			$state = $state->withUnknownReason(UnknownReason::CALLBACK_TIMING);
		}

		return $state;
	}

	/**
	 * Whether the statement hands a component-registering callable to an EVENT property of the
	 * tracked form — `$form->onSuccess[] = …` and its siblings.
	 *
	 * The answer this reaches for is the trinary's MAYBE, never its NO, and the difference is the
	 * whole point. Nette fires onSuccess during form PROCESSING, which precedes rendering, so the
	 * registration is deferred relative to the FACTORY and not relative to every reader; and the
	 * reader is any method on the class, including one reached from inside the handler itself or
	 * under `if ($form->isSuccess())`, by which time the control is attached. Absence is therefore
	 * not provable, and absence is what turns into a report.
	 *
	 * Only a callable whose body is READ and seen to register is matched. A handler that merely
	 * processes submitted values — which is what nearly every one of them does — leaves the shape
	 * closed, so the absence findings a closed shape exists to produce are kept.
	 */
	private function storesDeferredFormCallback(Node\Stmt $stmt, WalkContext $ctx): bool
	{
		$trackedName = $ctx->getTrackedName();
		$finder = new NodeFinder();

		foreach (EventCallbackStore::inNode($stmt) as $entry) {
			foreach ($this->callableFunctionLikes($entry['callable'], $ctx->getRootStmts()) as $functionLike) {
				if (
					$this->closureCapturesTracked($functionLike, $trackedName)
					&& $this->closureMutatesTracked($functionLike, $trackedName, $ctx->getRecordsByNode(), $finder)
				) {
					return true;
				}

				// The form an event hands its handler is the one the property lives on, so a
				// registration on a container-typed PARAMETER is one on the tracked form only when
				// the store's receiver is the tracked variable itself. `$button->onClick[]` gets the
				// button.
				if ($entry['receiver'] === $trackedName && $this->registersOnContainerParam($functionLike)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Node ids inside a callable stored on an event property that captures the tracked form and
	 * registers on it. Its ops describe a form state no render-time reader sees, so they are dropped
	 * from the walk exactly as a proven-later callable argument's are — the shape opens beside them
	 * (storesDeferredFormCallback) rather than closing over a name that is not there yet.
	 *
	 * @param array<Node\Stmt> $stmts
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 * @return array<int, true>
	 */
	private function eventStoredCallbackNodeIds(array $stmts, string $trackedName, array $recordsByNode): array
	{
		$finder = new NodeFinder();
		$ids = [];
		foreach (EventCallbackStore::inNode($stmts) as $entry) {
			foreach ($this->callableFunctionLikes($entry['callable'], $stmts) as $functionLike) {
				if (
					!$this->closureCapturesTracked($functionLike, $trackedName)
					|| !$this->closureMutatesTracked($functionLike, $trackedName, $recordsByNode, $finder)
				) {
					continue;
				}

				foreach ($finder->find($functionLike, static fn (Node $n): bool => true) as $inner) {
					if ($inner === $functionLike) {
						continue;
					}

					$ids[spl_object_id($inner)] = true;
				}
			}
		}

		return $ids;
	}

	/**
	 * The function-likes a stored callable can be READ as. A spelling this cannot resolve — a string
	 * callable, one arriving as a parameter or read off a property, one built by a call — yields
	 * nothing, and an unresolved callable leaves the shape exactly as it found it.
	 *
	 * @param array<Node\Stmt> $rootStmts
	 * @return list<FunctionLike>
	 */
	private function callableFunctionLikes(Expr $expr, array $rootStmts, int $depth = 0): array
	{
		if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
			return [$expr];
		}

		if ($expr instanceof Variable && is_string($expr->name)) {
			return $depth === 0 ? $this->closureLiteralsBoundTo($expr->name, $rootStmts) : [];
		}

		if (
			$depth === 0
			&& $expr instanceof StaticCall
			&& $expr->class instanceof Node\Name
			&& ltrim($expr->class->toString(), '\\') === \Closure::class
			&& $expr->name instanceof Identifier
			&& $expr->name->toString() === 'fromCallable'
			&& !$expr->isFirstClassCallable()
		) {
			$args = $expr->getArgs();

			return $args === [] ? [] : $this->callableFunctionLikes($args[0]->value, $rootStmts, $depth + 1);
		}

		$method = $this->namedMethodNode($expr);

		return $method === null ? [] : [$method];
	}

	/**
	 * Closure literals bound to a local of this name anywhere in the walked body. Reading the whole
	 * body rather than only what precedes the store is deliberate: a name bound on one path and
	 * stored on another still ends up on the event, and over-reading here only ever opens the shape.
	 *
	 * @param array<Node\Stmt> $rootStmts
	 * @return list<FunctionLike>
	 */
	private function closureLiteralsBoundTo(string $name, array $rootStmts): array
	{
		$found = [];
		foreach ((new NodeFinder())->findInstanceOf($rootStmts, Assign::class) as $assign) {
			if (
				$assign->var instanceof Variable
				&& $assign->var->name === $name
				&& ($assign->expr instanceof Closure || $assign->expr instanceof ArrowFunction)
			) {
				$found[] = $assign->expr;
			}
		}

		return $found;
	}

	/**
	 * The method an array callable or a first-class callable NAMES: `[$this, 'handler']`,
	 * `[Foo::class, 'handler']`, `$this->handler(...)`, `Foo::handler(...)`.
	 */
	private function namedMethodNode(Expr $expr): ?ClassMethod
	{
		if ($expr instanceof Array_ && count($expr->items) === 2) {
			$method = $expr->items[1]->value;
			$class = $this->callableTargetClass($expr->items[0]->value);

			return $class === null || !$method instanceof StringLiteral
				? null
				: $this->callees->methodNodeOf($class, $method->value);
		}

		if ($expr instanceof MethodCall && $expr->isFirstClassCallable() && $expr->name instanceof Identifier) {
			$class = $this->callableTargetClass($expr->var);

			return $class === null ? null : $this->callees->methodNodeOf($class, $expr->name->toString());
		}

		if (
			$expr instanceof StaticCall
			&& $expr->isFirstClassCallable()
			&& $expr->name instanceof Identifier
			&& $expr->class instanceof Node\Name
		) {
			$class = $this->resolveCallableClassName($expr->class->toString());

			return $class === null ? null : $this->callees->methodNodeOf($class, $expr->name->toString());
		}

		return null;
	}

	/**
	 * The class a callable's first element names. `parent` is declined: which class it means depends
	 * on the owner's own parent, which a walk keyed on a file and a function-like does not decide.
	 */
	private function callableTargetClass(Expr $target): ?string
	{
		if ($target instanceof Variable) {
			return $target->name === 'this' ? $this->walkOwnerClass : null;
		}

		if (
			$target instanceof Node\Expr\ClassConstFetch
			&& $target->class instanceof Node\Name
			&& $target->name instanceof Identifier
			&& $target->name->toString() === 'class'
		) {
			return $this->resolveCallableClassName($target->class->toString());
		}

		return $target instanceof StringLiteral ? ltrim($target->value, '\\') : null;
	}

	private function resolveCallableClassName(string $name): ?string
	{
		$lower = strtolower($name);
		if ($lower === 'self' || $lower === 'static') {
			return $this->walkOwnerClass;
		}

		return $lower === 'parent' ? null : ltrim($name, '\\');
	}

	/**
	 * Whether the callable registers a component on a parameter typed as a Nette container — the form
	 * the event hands its handler. A parameter that is untyped, or typed as something else, is
	 * declined: what the handler is given then is not readable off the signature.
	 */
	private function registersOnContainerParam(FunctionLike $functionLike): bool
	{
		$container = new ObjectType(NetteContainer::class);
		$reflection = $this->factory->getReflectionProvider();

		foreach ($functionLike->getParams() as $param) {
			if (!$param->var instanceof Variable || !is_string($param->var->name)) {
				continue;
			}

			$type = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;
			if (!$type instanceof Node\Name) {
				continue;
			}

			$paramClass = ltrim($type->toString(), '\\');
			if (
				!$reflection->hasClass($paramClass)
				|| !$container->isSuperTypeOf(new ObjectType($paramClass))->yes()
			) {
				continue;
			}

			if (self::registersComponentOn($functionLike->getStmts() ?? [], $param->var->name)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<Node> $stmts
	 */
	private static function registersComponentOn(array $stmts, string $varName): bool
	{
		$finder = new NodeFinder();

		$calls = array_merge(
			$finder->findInstanceOf($stmts, MethodCall::class),
			$finder->findInstanceOf($stmts, NullsafeMethodCall::class),
		);
		foreach ($calls as $call) {
			if (
				$call->name instanceof Identifier
				&& RegisteringMethodName::matches($call->name->toString())
				&& self::rootsAtVariable($call->var, $varName)
			) {
				return true;
			}
		}

		foreach ($finder->findInstanceOf($stmts, Assign::class) as $assign) {
			if ($assign->var instanceof ArrayDimFetch && self::rootsAtVariable($assign->var, $varName)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Node ids inside callable arguments that are not proven to run before the form is returned.
	 * Their component-affecting ops describe a form state no reader sees at render time, so they
	 * are dropped from the walk exactly as a by-ref closure's are.
	 *
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 * @return array<int, true>
	 */
	private function deferredCallbackNodeIds(Node $haystack, string $trackedName, array $recordsByNode): array
	{
		$ids = [];
		foreach ($this->mutatingCallbackArguments($haystack, $trackedName, $recordsByNode) as $entry) {
			if ($this->callees->parameterImmediacy($entry['call'], $entry['index'], $this->walkOwnerClass)->yes()) {
				continue;
			}

			foreach ((new NodeFinder())->find($entry['closure'], static fn (Node $n): bool => true) as $inner) {
				if ($inner === $entry['closure']) {
					continue;
				}

				$ids[spl_object_id($inner)] = true;
			}
		}

		return $ids;
	}

	/**
	 * Closure literals passed straight to a call whose body mutates the tracked form.
	 *
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 * @return list<array{call: CallLike, index: int, closure: Closure|ArrowFunction}>
	 */
	private function mutatingCallbackArguments(Node $haystack, string $trackedName, array $recordsByNode): array
	{
		$finder = new NodeFinder();
		$found = [];
		foreach ($finder->find(
			$haystack,
			static fn (Node $n): bool => $n instanceof MethodCall
				|| $n instanceof StaticCall
				|| $n instanceof FuncCall,
		) as $call) {
			assert($call instanceof CallLike);
			if ($call->isFirstClassCallable()) {
				continue;
			}

			$position = 0;
			foreach ($call->getArgs() as $arg) {
				$closure = $arg->value;
				if (
					($closure instanceof Closure || $closure instanceof ArrowFunction)
					&& $arg->name === null
					&& !$arg->unpack
					&& $this->closureCapturesTracked($closure, $trackedName)
					&& $this->closureMutatesTracked($closure, $trackedName, $recordsByNode, $finder)
				) {
					$found[] = ['call' => $call, 'index' => $position, 'closure' => $closure];
				}

				$position++;
			}
		}

		return $found;
	}

	/**
	 * Whether the closure's `$trackedName` IS this walk's object. A parameter of that name is a
	 * different variable that merely shares the spelling — the case a replicator factory closure
	 * (`addDynamic('x', function (Container $container) {…})`) presents when the walk is resolving
	 * the container named `$container`, and one the walk deliberately reads through, since that body
	 * is where the factory's own shape comes from. Only a genuine capture — an explicit `use`, or an
	 * arrow function's implicit one — puts the enclosing form in the closure's hands, and only then
	 * does the timing of the call matter.
	 */
	private function closureCapturesTracked(Node $closure, string $trackedName): bool
	{
		foreach ($closure instanceof Closure ? $closure->params : [] as $param) {
			if ($param->var instanceof Variable && $param->var->name === $trackedName) {
				return false;
			}
		}

		if ($closure instanceof ArrowFunction) {
			foreach ($closure->params as $param) {
				if ($param->var instanceof Variable && $param->var->name === $trackedName) {
					return false;
				}
			}

			return true;
		}

		if (!$closure instanceof Closure) {
			return false;
		}

		foreach ($closure->uses as $use) {
			if ($use->var->name === $trackedName) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 */
	private function closureMutatesTracked(
		Node $closure,
		string $trackedName,
		array $recordsByNode,
		NodeFinder $finder
	): bool
	{
		foreach ($finder->find(
			$closure,
			static fn (Node $n): bool => $n->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode,
		) as $node) {
			if (isset($recordsByNode[spl_object_id($node)]) && $this->targetsTrackedVariable($node, $trackedName)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A by-ref closure capturing the tracked form that is not (yet) bound to a local
	 * variable — passed directly as a call argument, stored to a property or array, or
	 * returned — may run anywhere the closure ends up, so the shape opens. The two sound
	 * absorption sites, `$build = function () use (&$form) {…};` and the immediately
	 * invoked `(function () use (&$form) {…})()`, are handled by processByRefClosures and
	 * excluded here.
	 */
	private function unassignedByRefClosureEscapes(Node\Stmt $stmt, string $trackedName): bool
	{
		$finder = new NodeFinder();

		$absorbedIds = [];
		foreach ($finder->find(
			$stmt,
			static fn (Node $n): bool => $n instanceof Assign
				&& $n->var instanceof Variable
				&& $n->expr instanceof Closure,
		) as $assign) {
			if (!$assign instanceof Assign || !$assign->expr instanceof Closure) {
				continue;
			}

			$absorbedIds[spl_object_id($assign->expr)] = true;
		}

		foreach ($finder->find(
			$stmt,
			static fn (Node $n): bool => $n instanceof FuncCall
				&& $n->name instanceof Closure
				&& !$n->isFirstClassCallable(),
		) as $call) {
			if (!$call instanceof FuncCall || !$call->name instanceof Closure) {
				continue;
			}

			$absorbedIds[spl_object_id($call->name)] = true;
		}

		foreach ($finder->find(
			$stmt,
			fn (Node $n): bool => $n instanceof Closure && $this->capturesByRef($n, $trackedName),
		) as $closure) {
			if (!isset($absorbedIds[spl_object_id($closure)])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The tracked form stored where the walk can no longer account for every holder of the
	 * object: a property or static property (`$this->form = $form;`), an array dim
	 * (`$arr[] = $form;`, `$this->forms[] = $form;` — except the composed container-attach,
	 * see isComposedContainerAttach), or an array literal handed elsewhere
	 * (`$pair = [$form, $meta];`). A destructuring source (`[$alias] = [$form]` /
	 * `list($alias) = [$form]`) is excluded from the array-literal check — aliasTargetsOf
	 * already tracks that alias precisely.
	 */
	private function trackedVarStoreEscapes(Node\Stmt $stmt, WalkContext $ctx): bool
	{
		$trackedName = $ctx->getTrackedName();
		$trackedClass = $ctx->getTrackedClass();
		$recordsByNode = $ctx->getRecordsByNode();

		$finder = new NodeFinder();

		foreach ($finder->findInstanceOf($stmt, Assign::class) as $assign) {
			if (!$assign->expr instanceof Variable || $assign->expr->name !== $trackedName) {
				continue;
			}

			if (
				$assign->var instanceof Node\Expr\PropertyFetch
				|| $assign->var instanceof Node\Expr\StaticPropertyFetch
			) {
				return true;
			}

			if (
				$assign->var instanceof ArrayDimFetch
				&& !$this->isComposedContainerAttach($assign, $trackedClass, $recordsByNode)
			) {
				return true;
			}
		}

		$destructureSourceIds = [];
		foreach ($finder->find(
			$stmt,
			static fn (Node $n): bool => $n instanceof Assign && ($n->var instanceof Array_ || $n->var instanceof List_),
		) as $destructure) {
			if (!$destructure instanceof Assign) {
				continue;
			}

			$destructureSourceIds[spl_object_id($destructure->expr)] = true;
		}

		foreach ($finder->findInstanceOf($stmt, Array_::class) as $array) {
			if (isset($destructureSourceIds[spl_object_id($array)])) {
				continue;
			}

			foreach ($array->items as $item) {
				if ($item->value instanceof Variable && $item->value->name === $trackedName) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * `$parent['slot'] = $tracked` is the child-container attach, not an escape, when the
	 * walk composing $parent models it: $parent is a variable some enclosing walk is
	 * tracking (composeContainer re-walks the tracked child under that walk), the assign is
	 * a tagged record, its dim resolves to a literal name and its value resolves to
	 * KIND_CONTAINER — the exact conditions attachChildShapes composes under. Any other
	 * array-dim store of the tracked form (plain array, dynamic name, unwalked base) escapes.
	 *
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 */
	private function isComposedContainerAttach(Assign $assign, string $trackedClass, array $recordsByNode): bool
	{
		if (!$assign->var instanceof ArrayDimFetch) {
			return false;
		}

		$base = $assign->var->var;
		if (
			!$base instanceof Variable
			|| !is_string($base->name)
			|| ($this->activeWalkNames[$base->name] ?? 0) === 0
		) {
			return false;
		}

		$rec = $recordsByNode[spl_object_id($assign)] ?? null;
		if ($rec === null) {
			return false;
		}

		$summary = $this->summarizeRecord($rec, $base->name, $trackedClass);
		$resolution = $summary->getResolution();

		return $summary->getName() !== null
			&& $resolution !== null
			&& $resolution->getKind() === ControlValueResolution::KIND_CONTAINER;
	}

	private function isAnalysisSentinel(Node $call): bool
	{
		if (!$call instanceof FuncCall || !$call->name instanceof Node\Name) {
			return false;
		}

		$name = ltrim($call->name->toString(), '\\');

		return in_array($name, [
			'OriPhpstan\Nette\Forms\Testing\\dumpComponent',
			'OriPhpstan\Nette\Forms\Testing\\assertComponent',
			'OriPhpstan\Nette\Forms\Testing\\dumpFormValues',
			'OriPhpstan\Nette\Forms\Testing\\assertFormValues',
			'PHPStan\\Testing\\assertType',
			'PHPStan\\dumpType',
		], true);
	}

	private function targetsTrackedVariable(Node $node, ?string $trackedName): bool
	{
		if ($trackedName === null) {
			return false;
		}

		if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
			return $node->var instanceof Variable && $node->var->name === $trackedName;
		}

		if ($node instanceof Assign && $node->var instanceof ArrayDimFetch) {
			return $node->var->var instanceof Variable && $node->var->var->name === $trackedName;
		}

		if ($node instanceof Unset_) {
			foreach ($node->vars as $var) {
				if (
					$var instanceof ArrayDimFetch
					&& $var->var instanceof Variable
					&& $var->var->name === $trackedName
				) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 * @return array<string, string>
	 */
	private function buildLiteralNameEnv(array $stmts): array
	{
		/** @var array<string, string|null> $seen null = seen more than once or non-literal */
		$seen = [];

		foreach ($stmts as $stmt) {
			if (!$stmt instanceof Expression) {
				continue;
			}

			if (
				$stmt->expr instanceof AssignOp
				&& $stmt->expr->var instanceof Variable
				&& is_string($stmt->expr->var->name)
			) {
				$seen[$stmt->expr->var->name] = null;

				continue;
			}

			if (
				!$stmt->expr instanceof Assign
				|| !$stmt->expr->var instanceof Variable
				|| !is_string($stmt->expr->var->name)
			) {
				continue;
			}

			$varName = $stmt->expr->var->name;
			if (array_key_exists($varName, $seen)) {
				$seen[$varName] = null;

				continue;
			}

			$seen[$varName] = LiteralNameResolver::resolve($stmt->expr->expr, [], null, null);
		}

		$writeCounts = $this->countVariableBindings($stmts);

		$env = [];
		foreach ($seen as $varName => $value) {
			if ($value !== null && ($writeCounts[$varName] ?? 0) <= 1) {
				$env[$varName] = $value;
			}
		}

		return $env;
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 * @return array<string, int>
	 */
	private function countVariableBindings(array $stmts, bool $countClosureBindings = true): array
	{
		return VariableBindingCounter::count($stmts, $countClosureBindings);
	}

}
