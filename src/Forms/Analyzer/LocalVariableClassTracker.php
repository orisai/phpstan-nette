<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use Nette\Forms\Container as NetteContainer;
use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;
use function count;
use function is_string;
use function ltrim;
use function spl_object_id;

/**
 * Resolves a form/container variable's class from its defining site using reflection and the
 * method AST only — never a live PHPStan scope. This makes the on-demand form-shape recompute
 * independent of which file triggered it: when a consumer accesses a result-cached form, the
 * recompute runs in the consumer's scope, where `$scope->getType($form)` of the foreign form
 * variable resolves wrong; reading the class from the defining assignment instead yields the
 * same answer the defining method's own scope would.
 *
 * The supported defining sites mirror what the catalog/constructor resolvers already read
 * scope-free: `new X()`, a `$this->factory->create()` / `$this->method()` / `parent::m()` /
 * `X::m()` call (the method's declared return type), a typed parameter, `$this` (the owner
 * class), and an alias of another tracked variable. Anything else returns null so the caller
 * falls back to the (genuinely-defining) scope or leaves the shape open.
 */
final class LocalVariableClassTracker
{

	private ReflectionProvider $reflectionProvider;

	private ?DependencyRecorder $recorder;

	public function __construct(ReflectionProvider $reflectionProvider, ?DependencyRecorder $recorder = null)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->recorder = $recorder;
	}

	/**
	 * Class FQCN (no leading backslash) of the form/container the variable holds, resolved
	 * scope-free, or null when it cannot be determined or is not a Nette container subtype.
	 */
	public function resolveContainerClass(string $varName, FunctionLike $fn, ?string $ownerFqcn): ?string
	{
		$class = $this->resolveClass($varName, $fn, $ownerFqcn, []);
		if ($class === null) {
			return null;
		}

		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf(new ObjectType($class))->yes()) {
			return null;
		}

		return $class;
	}

	/**
	 * Class FQCN (no leading backslash) of the value a receiver expression evaluates to — a
	 * variable, `$this`, a `$this->prop`, or a method/static-call's declared return — resolved
	 * scope-free, so the on-demand builder-chain detector finds the same factory method the
	 * defining scope would. Null when it cannot be determined.
	 */
	public function resolveReceiverClass(Expr $receiver, FunctionLike $fn, ?string $ownerFqcn): ?string
	{
		return $this->receiverClass($receiver, $fn, $ownerFqcn, []);
	}

	/**
	 * Class FQCN (no leading backslash) of the value an arbitrary expression evaluates to, resolved
	 * scope-free. The receiver forms plus a `new X()`, which a receiver expression never is but a
	 * returned one routinely is. Null when it cannot be determined.
	 */
	public function resolveExpressionClass(Expr $expr, FunctionLike $fn, ?string $ownerFqcn): ?string
	{
		return $expr instanceof New_
			? $this->classOfExpr($expr, $fn, $ownerFqcn, [])
			: $this->receiverClass($expr, $fn, $ownerFqcn, []);
	}

	/**
	 * @param array<string, true> $visiting
	 */
	private function resolveClass(string $varName, FunctionLike $fn, ?string $ownerFqcn, array $visiting): ?string
	{
		if (isset($visiting[$varName])) {
			return null;
		}

		$visiting[$varName] = true;

		if ($varName === 'this') {
			return $ownerFqcn === null ? null : ltrim($ownerFqcn, '\\');
		}

		$paramClass = $this->paramClass($varName, $fn);
		if ($paramClass !== null) {
			return $paramClass;
		}

		$assigns = $this->assignmentsTo($varName, $fn);
		if (count($assigns) !== 1) {
			return null;
		}

		return $this->classOfExpr($assigns[0]->expr, $fn, $ownerFqcn, $visiting);
	}

	/**
	 * @param array<string, true> $visiting
	 */
	private function classOfExpr(Expr $rhs, FunctionLike $fn, ?string $ownerFqcn, array $visiting): ?string
	{
		if ($rhs instanceof New_ && $rhs->class instanceof Name) {
			return ltrim($rhs->class->toString(), '\\');
		}

		if ($rhs instanceof Variable && is_string($rhs->name)) {
			return $this->resolveClass($rhs->name, $fn, $ownerFqcn, $visiting);
		}

		if ($rhs instanceof MethodCall || $rhs instanceof NullsafeMethodCall) {
			return $this->methodCallReturnClass($rhs, $fn, $ownerFqcn, $visiting);
		}

		if ($rhs instanceof StaticCall) {
			return $this->staticCallReturnClass($rhs, $ownerFqcn);
		}

		return null;
	}

	/**
	 * @param MethodCall|NullsafeMethodCall $call
	 * @param array<string, true> $visiting
	 */
	private function methodCallReturnClass(Expr $call, FunctionLike $fn, ?string $ownerFqcn, array $visiting): ?string
	{
		if (!$call->name instanceof Identifier) {
			return null;
		}

		$receiverClass = $this->receiverClass($call->var, $fn, $ownerFqcn, $visiting);
		if ($receiverClass === null) {
			return null;
		}

		return $this->returnClassOfMethod($receiverClass, $call->name->toString());
	}

	/**
	 * @param array<string, true> $visiting
	 */
	private function receiverClass(Expr $receiver, FunctionLike $fn, ?string $ownerFqcn, array $visiting): ?string
	{
		if ($receiver instanceof Variable && is_string($receiver->name)) {
			return $this->resolveClass($receiver->name, $fn, $ownerFqcn, $visiting);
		}

		if ($receiver instanceof PropertyFetch
			&& $receiver->var instanceof Variable
			&& $receiver->var->name === 'this'
			&& $receiver->name instanceof Identifier
			&& $ownerFqcn !== null
		) {
			return $this->propertyClass($ownerFqcn, $receiver->name->toString());
		}

		if ($receiver instanceof MethodCall || $receiver instanceof NullsafeMethodCall) {
			return $this->methodCallReturnClass($receiver, $fn, $ownerFqcn, $visiting);
		}

		if ($receiver instanceof StaticCall) {
			return $this->staticCallReturnClass($receiver, $ownerFqcn);
		}

		return null;
	}

	private function staticCallReturnClass(StaticCall $call, ?string $ownerFqcn): ?string
	{
		if (!$call->name instanceof Identifier || !$call->class instanceof Name) {
			return null;
		}

		$className = $call->class->toString();
		if ($className === 'parent') {
			$owner = $this->classReflection($ownerFqcn);
			$parent = $owner === null ? null : $owner->getParentClass();

			return $parent === null ? null : $this->returnClassOfMethod($parent->getName(), $call->name->toString());
		}

		if ($className === 'self' || $className === 'static') {
			return $ownerFqcn === null ? null : $this->returnClassOfMethod($ownerFqcn, $call->name->toString());
		}

		return $this->returnClassOfMethod($className, $call->name->toString());
	}

	private function propertyClass(string $ownerFqcn, string $property): ?string
	{
		$class = $this->classReflection($ownerFqcn);
		while ($class !== null) {
			if ($class->hasNativeProperty($property)) {
				return $this->singleObjectClass($class->getNativeProperty($property)->getReadableType());
			}

			$class = $class->getParentClass();
		}

		return null;
	}

	private function returnClassOfMethod(string $classFqcn, string $method): ?string
	{
		$class = $this->classReflection($classFqcn);
		if ($class === null || !$class->hasNativeMethod($method)) {
			return null;
		}

		$returnType = $class->getNativeMethod($method)->getVariants()[0]->getReturnType();

		// A `$this`/`static` return binds late: the value's class is the receiver's own class —
		// exactly what a live scope's resolveStatic yields — not the ancestor that declared the
		// fluent method; the reflected type names the declaring class and would break the chain
		// at the next hop (e.g. an abstract, untyped terminal create()).
		if ($returnType instanceof StaticType) {
			return ltrim($classFqcn, '\\');
		}

		return $this->singleObjectClass($returnType);
	}

	private function classReflection(?string $classFqcn): ?ClassReflection
	{
		if ($classFqcn === null) {
			return null;
		}

		$fqcn = ltrim($classFqcn, '\\');
		if (!$this->reflectionProvider->hasClass($fqcn)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($fqcn);
		if ($this->recorder !== null) {
			$file = $class->getFileName();
			if ($file !== null) {
				$this->recorder->record($file);
			}
		}

		return $class;
	}

	private function singleObjectClass(Type $type): ?string
	{
		$classes = $type->getObjectClassNames();

		return count($classes) === 1 ? $classes[0] : null;
	}

	private function paramClass(string $varName, FunctionLike $fn): ?string
	{
		foreach ($fn->getParams() as $param) {
			if (!$param->var instanceof Variable || $param->var->name !== $varName) {
				continue;
			}

			if ($param->type instanceof Name) {
				return ltrim($param->type->toString(), '\\');
			}

			if ($param->type instanceof Node\NullableType && $param->type->type instanceof Name) {
				return ltrim($param->type->type->toString(), '\\');
			}

			return null;
		}

		return null;
	}

	/**
	 * Direct assignments to the variable in the function body, excluding any nested inside a
	 * closure or arrow function — those write a shadowing local (e.g. an onClick handler's own
	 * `$form = $submitButton->getForm()`), not the form-building variable being resolved.
	 *
	 * @return list<Assign>
	 */
	private function assignmentsTo(string $varName, FunctionLike $fn): array
	{
		$finder = new NodeFinder();
		$stmts = $fn->getStmts() ?? [];
		$nestedIds = $this->nestedFunctionNodeIds($stmts, $finder);

		$assigns = [];
		foreach ($finder->findInstanceOf($stmts, Assign::class) as $assign) {
			if (
				$assign->var instanceof Variable
				&& $assign->var->name === $varName
				&& !isset($nestedIds[spl_object_id($assign)])
			) {
				$assigns[] = $assign;
			}
		}

		return $assigns;
	}

	/**
	 * @param array<Node> $stmts
	 * @return array<int, true>
	 */
	private function nestedFunctionNodeIds(array $stmts, NodeFinder $finder): array
	{
		$ids = [];
		foreach ($finder->find(
			$stmts,
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		) as $fn) {
			foreach ($finder->find($fn, static fn (Node $n): bool => true) as $inner) {
				if ($inner === $fn) {
					continue;
				}

				$ids[spl_object_id($inner)] = true;
			}
		}

		return $ids;
	}

}
