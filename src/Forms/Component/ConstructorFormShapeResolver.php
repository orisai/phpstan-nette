<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;
use OriPhpstan\Nette\Forms\Catalog\ControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Graph\FirstClassCallableDetector;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use function in_array;
use function is_string;
use function ltrim;
use function spl_object_id;
use function strncmp;

/**
 * Shapes a form built in its own constructor — `new XxxForm()` where XxxForm::__construct
 * adds controls (`$this->addX('name')...` or `$this['name'] = new XInput()`). Resolved on
 * demand from class reflection plus the constructor AST, so it needs neither a Collector
 * nor a live scope and is therefore order-independent and deterministic; whatever it
 * cannot read precisely (a container, a dynamic name, a loop, a nullable control) leaves
 * the shape open rather than wrong. A statement the shape-building pass does not recognise
 * still closes the shape only if it is provably inert, judged deny-by-default: the whole
 * subtree must be free of static calls, free function calls, `new` expressions and closures
 * (each can hand `$this` — or an alias of it — to code this predicate does not read); `$this`
 * may appear only as the receiver of a method call; and every method call must be on `$this`
 * with a literal non-mutating name resolving either to a same-class *private* helper (dispatch
 * cannot be overridden) whose body passes the same predicate recursively, or to an inherited
 * accessor on the explicit INERT_INHERITED_ACCESSORS allowlist. Everything else — an inherited
 * or magic method of any other name, a protected/public same-class helper, a dynamic call, a
 * non-$this receiver — keeps the CONSTRUCTOR_BUILD marker. The result is memoised in the
 * interprocedural cache so the first caller computes it once and every later caller reuses
 * the identical shape.
 */
final class ConstructorFormShapeResolver
{

	/**
	 * Nette component-tree mutators not spelled with the `add` prefix; the add* family is
	 * caught by name convention instead (Container::addComponent, Form::addProtection, ...).
	 * Rejected on EVERY receiver, not just $this, so an aliased or fetched component cannot
	 * be mutated behind the predicate's back.
	 */
	private const MUTATING_METHOD_NAMES = ['removeComponent', 'setParent', 'offsetSet', 'offsetUnset', 'monitor'];

	/**
	 * Inherited vendor accessors an inert constructor may call on $this — each returns an
	 * object whose mutation configures rendering or markup and cannot add, remove or replace
	 * a component (the tree is reachable only through the add* / MUTATING_METHOD_NAMES call
	 * surface, which is rejected receiver-blind above, or `$this[...]`, rejected by position):
	 *  - getRenderer: the form renderer; wrapper/option writes shape HTML output only (the
	 *    ContentForm `configureRender()` pattern this predicate exists to close).
	 *  - getElementPrototype: the form's <form> Html prototype; attribute writes are markup only.
	 * Any inherited name NOT listed here rejects — the body that would run is outside this
	 * class's AST, so it is unprovable by construction.
	 */
	private const INERT_INHERITED_ACCESSORS = ['getRenderer', 'getElementPrototype'];

	private ReflectionProvider $reflectionProvider;

	private ControlValueTypeResolver $catalog;

	private FormShapeCache $cache;

	private TraitAwareMethodLocator $methodLocator;

	public function __construct(
		ReflectionProvider $reflectionProvider,
		ControlValueTypeResolver $catalog,
		Parser $parser,
		FormShapeCache $cache
	)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->catalog = $catalog;
		$this->cache = $cache;
		$this->methodLocator = new TraitAwareMethodLocator($parser);
	}

	public function resolve(string $fqcn): ?FormShape
	{
		$fqcn = ltrim($fqcn, '\\');
		$key = InterproceduralShapeKey::forConstructor($fqcn);
		$cached = $this->cache->lookupInterprocedural($key);
		if ($cached !== null) {
			return $cached;
		}

		[$shape, $deps] = $this->cache->computeShapeWithDependencies(fn (): ?FormShape => $this->computeShape($fqcn));

		if ($shape !== null) {
			$this->cache->storeInterprocedural($key, $shape, $deps);
		}

		return $shape;
	}

	private function computeShape(string $fqcn): ?FormShape
	{
		if (!$this->reflectionProvider->hasClass($fqcn)) {
			return null;
		}

		$classReflection = $this->reflectionProvider->getClass($fqcn);
		if (!$classReflection->hasConstructor()) {
			return null;
		}

		if ($classReflection->getConstructor()->getDeclaringClass()->getName() !== $fqcn) {
			// The constructor is inherited — its controls belong to the declaring class.
			return null;
		}

		$node = $this->constructorNode($classReflection);
		if ($node === null) {
			return null;
		}

		/** @var array<string, ControlValueResolution> $controls */
		$controls = [];
		/** @var array<string, bool> $required */
		$required = [];
		/** @var array<string, bool> $nullable */
		$nullable = [];
		/** @var array<string, bool> $omitted */
		$omitted = [];
		/** @var array<string, string> $locals */
		$locals = [];
		$open = false;

		foreach ($node->getStmts() ?? [] as $stmt) {
			if ($this->isParentConstructorCall($stmt)) {
				continue;
			}

			if ($this->trackLocalAssignment($stmt, $locals, $classReflection)) {
				if ($this->assignmentReachesThis($stmt)) {
					$open = true;
				}

				continue;
			}

			if ($this->applyStatement($classReflection, $stmt, $controls, $required, $nullable, $omitted, $locals)) {
				continue;
			}

			if ($this->isInertStatement($classReflection, $stmt, [])) {
				continue;
			}

			$open = true;
		}

		$slots = [];
		foreach ($controls as $name => $resolution) {
			if ($resolution->getKind() === ControlValueResolution::KIND_OMITTED) {
				continue;
			}

			if ($resolution->getKind() !== ControlValueResolution::KIND_VALUE || ($nullable[$name] ?? false)) {
				$open = true;

				continue;
			}

			$slots[$name] = new ComponentSlot(
				$name,
				$resolution->getValueType() ?? new MixedType(),
				Certainty::HAPPENS,
				[],
				$resolution->getControlClass(),
				false,
				$resolution->getAcceptedSetSpec(),
				$required[$name] ?? false,
				false,
				$omitted[$name] ?? false ? Certainty::HAPPENS : Certainty::NEVER,
			);
		}

		$unknown = $open ? new UnknownInfo([UnknownReason::CONSTRUCTOR_BUILD]) : new UnknownInfo();

		// componentTypes stays empty here, so the presence axis beside it is vacuously complete rather
		// than unpopulated: the KIND_OMITTED arm above `continue`s without recording the button at all,
		// which is why a constructor-built form answers nothing about its submit on either axis. Giving
		// this resolver a componentTypes channel is a separate change with its own absence-reporting
		// consequences (FormShapeUnknownAccessRule reads componentTypes to stay SILENT), not a
		// threading gap in this one.
		return new FormShape($fqcn, $slots, [], [], $unknown, []);
	}

	/**
	 * @param array<string, ControlValueResolution> $controls
	 * @param array<string, bool> $required
	 * @param array<string, bool> $nullable
	 * @param array<string, bool> $omitted
	 * @param array<string, string> $locals
	 */
	private function applyStatement(
		ClassReflection $classReflection,
		Node $stmt,
		array &$controls,
		array &$required,
		array &$nullable,
		array &$omitted,
		array $locals
	): bool
	{
		// A first-class callable anywhere in the statement means an add name, wrapper or
		// omission decision may run later with unknown arguments — not recognisable, so the
		// caller falls through to the inertness judgement (which rejects it → shape opens).
		if (FirstClassCallableDetector::inSubtree($stmt)) {
			return false;
		}

		$add = $this->controlAddCall($stmt);
		if ($add !== null) {
			$name = $this->resolveName($add->getArgs()[0]->value ?? null, $locals, $classReflection);
			$method = $add->name instanceof Identifier ? $add->name->toString() : null;
			if ($name === null || $method === null || !$classReflection->hasNativeMethod($method)) {
				return false;
			}

			$returnType = $classReflection->getNativeMethod($method)->getVariants()[0]->getReturnType();
			$controls[$name] = $this->catalog->resolveControlType($returnType);
			$this->collectWrappers($stmt, $name, $required, $nullable, $omitted);

			return true;
		}

		$offsetAssign = $this->offsetControlAssign($stmt, $locals, $classReflection);
		if ($offsetAssign !== null) {
			[$name, $controlClass] = $offsetAssign;
			$controls[$name] = $this->catalog->resolveControlType(new ObjectType($controlClass));
			$this->collectWrappers($stmt, $name, $required, $nullable, $omitted);

			return true;
		}

		$offsetName = $this->offsetMethodTarget($stmt, $locals, $classReflection);
		if ($offsetName !== null) {
			$this->collectWrappers($stmt, $offsetName, $required, $nullable, $omitted);

			return true;
		}

		return false;
	}

	private function controlAddCall(Node $stmt): ?MethodCall
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return null;
		}

		$call = $stmt->expr;
		while ($call->var instanceof MethodCall) {
			$call = $call->var;
		}

		if (
			$call->var instanceof Variable
			&& $call->var->name === 'this'
			&& $call->name instanceof Identifier
			&& strncmp($call->name->toString(), 'add', 3) === 0
		) {
			return $call;
		}

		return null;
	}

	/**
	 * @param array<string, string> $locals
	 * @return array{string, string}|null [component name, control class]
	 */
	private function offsetControlAssign(Node $stmt, array $locals, ClassReflection $classReflection): ?array
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return null;
		}

		$assign = $stmt->expr;
		if (
			!$assign->var instanceof ArrayDimFetch
			|| !$assign->var->var instanceof Variable
			|| $assign->var->var->name !== 'this'
			|| $assign->var->dim === null
			|| !$assign->expr instanceof New_
			|| !$assign->expr->class instanceof Name
		) {
			return null;
		}

		$name = $this->resolveName($assign->var->dim, $locals, $classReflection);

		return $name === null ? null : [$name, $assign->expr->class->toString()];
	}

	/**
	 * @param array<string, string> $locals
	 */
	private function offsetMethodTarget(Node $stmt, array $locals, ClassReflection $classReflection): ?string
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return null;
		}

		$call = $stmt->expr;
		while ($call->var instanceof MethodCall) {
			$call = $call->var;
		}

		if (
			$call->var instanceof ArrayDimFetch
			&& $call->var->var instanceof Variable
			&& $call->var->var->name === 'this'
			&& $call->var->dim !== null
		) {
			return $this->resolveName($call->var->dim, $locals, $classReflection);
		}

		return null;
	}

	/**
	 * @param array<string, bool> $required
	 * @param array<string, bool> $nullable
	 * @param array<string, bool> $omitted
	 */
	private function collectWrappers(
		Node $stmt,
		string $name,
		array &$required,
		array &$nullable,
		array &$omitted
	): void
	{
		foreach ((new NodeFinder())->findInstanceOf([$stmt], MethodCall::class) as $call) {
			if (!$call->name instanceof Identifier) {
				continue;
			}

			$method = $call->name->toString();
			if ($method === 'setRequired' && !$this->isDisabledByFalse($call)) {
				$required[$name] = true;
			} elseif ($method === 'setNullable') {
				$nullable[$name] = true;
			} elseif (($method === 'setDisabled' || $method === 'setOmitted') && !$this->isDisabledByFalse($call)) {
				$omitted[$name] = true;
			}
		}
	}

	private function isDisabledByFalse(MethodCall $call): bool
	{
		$arg = $call->getArgs()[0]->value ?? null;

		return $arg instanceof Node\Expr\ConstFetch && $arg->name->toLowerString() === 'false';
	}

	/**
	 * @param array<string, string> $locals
	 */
	private function resolveName(?Node $expr, array $locals, ClassReflection $classReflection): ?string
	{
		return LiteralNameResolver::resolve(
			$expr,
			$locals,
			$classReflection,
			$this->reflectionProvider,
			$this->cache->recorder(),
		);
	}

	/**
	 * @param array<string, string> $locals
	 */
	private function trackLocalAssignment(Node $stmt, array &$locals, ClassReflection $classReflection): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return false;
		}

		$assign = $stmt->expr;
		if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
			return false;
		}

		$varName = $assign->var->name;
		$resolved = $this->resolveName($assign->expr, $locals, $classReflection);
		if ($resolved !== null) {
			$locals[$varName] = $resolved;
		} else {
			unset($locals[$varName]);
		}

		return true;
	}

	private function constructorNode(ClassReflection $classReflection): ?ClassMethod
	{
		$constructor = $classReflection->getNativeReflection()->getConstructor();
		if ($constructor === null) {
			return null;
		}

		return $this->methodLocator->locate($constructor);
	}

	private function isParentConstructorCall(Node $stmt): bool
	{
		return $stmt instanceof Expression
			&& $stmt->expr instanceof StaticCall
			&& $stmt->expr->class instanceof Name
			&& $stmt->expr->class->toString() === 'parent'
			&& $stmt->expr->name instanceof Identifier
			&& $stmt->expr->name->toString() === '__construct';
	}

	/**
	 * @param array<string, true> $visiting
	 */
	private function isInertStatement(ClassReflection $classReflection, Node $stmt, array $visiting): bool
	{
		$finder = new NodeFinder();

		// Any construct that can transfer control (and with it $this or an alias) to code this
		// predicate does not read rejects the whole statement: static and free function calls,
		// object construction (new X($this) mutates through the foreign constructor) and
		// closures (which capture $this implicitly).
		if (
			$finder->findFirst(
				[$stmt],
				static fn (Node $n): bool => $n instanceof StaticCall
					|| $n instanceof FuncCall
					|| $n instanceof New_
					|| $n instanceof Closure
					|| $n instanceof ArrowFunction,
			) !== null
		) {
			return false;
		}

		// $this may appear ONLY as the receiver of a method call vetted below. Any other
		// position — argument, assignment side, property or offset base, alias source —
		// escapes the form under construction into unmodeled territory.
		$receiverPositions = [];
		foreach ($finder->findInstanceOf([$stmt], MethodCall::class) as $call) {
			if ($call->var instanceof Variable && $call->var->name === 'this') {
				$receiverPositions[spl_object_id($call->var)] = true;
			}
		}

		foreach ($finder->findInstanceOf([$stmt], Variable::class) as $variable) {
			if ($variable->name === 'this' && !isset($receiverPositions[spl_object_id($variable)])) {
				return false;
			}
		}

		foreach ($finder->findInstanceOf([$stmt], MethodCall::class) as $call) {
			if (!$call->name instanceof Identifier) {
				return false;
			}

			$name = $call->name->toString();
			if (strncmp($name, 'add', 3) === 0 || in_array($name, self::MUTATING_METHOD_NAMES, true)) {
				return false;
			}

			if (!$call->var instanceof Variable || $call->var->name !== 'this') {
				// A call on any non-$this receiver (a local, a chained result) dispatches to
				// code whose relation to the form is not modeled here.
				return false;
			}

			if (!$classReflection->hasNativeMethod($name)) {
				// Magic __call — the dispatched body is unknowable.
				return false;
			}

			$declaringClass = $classReflection->getNativeMethod($name)->getDeclaringClass();
			if ($declaringClass->getName() === $classReflection->getName()) {
				if (!$this->isInertHelperMethod($classReflection, $name, $visiting)) {
					return false;
				}

				continue;
			}

			if (!in_array($name, self::INERT_INHERITED_ACCESSORS, true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A tracked local-name assignment is consumed silently by the shape pass, so its RHS must
	 * not leak $this (new X($this), $alias = $this) or a $this-capturing closure — either
	 * would let the consumed statement mutate the form invisibly.
	 */
	private function assignmentReachesThis(Node $stmt): bool
	{
		return (new NodeFinder())->findFirst(
			[$stmt],
			static fn (Node $n): bool => ($n instanceof Variable && $n->name === 'this')
				|| $n instanceof Closure
				|| $n instanceof ArrowFunction,
		) !== null;
	}

	/**
	 * @param array<string, true> $visiting
	 */
	private function isInertHelperMethod(ClassReflection $classReflection, string $method, array $visiting): bool
	{
		if (isset($visiting[$method])) {
			return false;
		}

		$methodReflection = $classReflection->getNativeMethod($method);
		if (!$methodReflection->isPrivate()) {
			// A protected/public same-class method is dynamically dispatched: a subclass
			// inheriting this constructor could override it, so its body here proves
			// nothing about what actually runs.
			return false;
		}

		$node = $this->methodLocator->locate($classReflection->getNativeReflection()->getMethod($method));
		if ($node === null || $node->getStmts() === null) {
			return false;
		}

		$visiting[$method] = true;

		foreach ($node->getStmts() as $inner) {
			if (!$this->isInertStatement($classReflection, $inner, $visiting)) {
				return false;
			}
		}

		return true;
	}

}
