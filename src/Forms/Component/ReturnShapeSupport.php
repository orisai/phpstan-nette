<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Forms\Container as NetteContainer;
use Nette\Forms\Form as NetteForm;
use OriPhpstan\Nette\Forms\Graph\ClosureScope;
use OriPhpstan\Nette\Forms\Graph\VariableBindingCounter;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use function count;
use function ltrim;
use function spl_object_id;
use function strpos;

final class ReturnShapeSupport
{

	private ReflectionProvider $reflectionProvider;

	private ConstructedFormShapeResolver $constructedResolver;

	public function __construct(
		ReflectionProvider $reflectionProvider,
		ConstructedFormShapeResolver $constructedResolver
	)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->constructedResolver = $constructedResolver;
	}

	/**
	 * The single method-level (closure-excluded) binding of the tracked name, which must be a
	 * plain assignment; null when the name is bound zero times or more than once by ANY rebind
	 * family the walk resets for (assignment, by-ref, destructure, foreach var, catch var).
	 * With more than one binding the final one is control-flow dependent (branch arms, later
	 * overwrites), so no single source can be trusted — compensation is skipped and the rebind
	 * marker survives.
	 *
	 * Static for the same reason isClassNameUnresolved() below is: it reads nothing but its two
	 * arguments, and ContainerModel carried a byte-identical private copy until this became callable
	 * without holding an instance. A helper a consumer can respell is a helper that drifts.
	 */
	public static function soleTrackedAssign(ClassMethod $fn, string $trackedName): ?Assign
	{
		$stmts = $fn->getStmts() ?? [];
		if ((VariableBindingCounter::count($stmts, false)[$trackedName] ?? 0) !== 1) {
			return null;
		}

		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		foreach ((new NodeFinder())->findInstanceOf($stmts, Assign::class) as $assign) {
			if (isset($closureInnerIds[spl_object_id($assign)])) {
				continue;
			}

			if ($assign->var instanceof Variable && $assign->var->name === $trackedName) {
				return $assign;
			}
		}

		return null;
	}

	/**
	 * What the constructor of a `$form = new X()` sole binding builds, resolved through the SAME
	 * ConstructedFormShapeResolver every other `new X()` spelling goes through — the factory's
	 * `return new X()`, the analyzer's rebind absorption, the vendor-method arm. Asking
	 * ConstructorFormShapeResolver directly here made one class answer differently depending on how
	 * the caller held the form: it skipped the arg-count carve-out that keeps a bare Nette base
	 * construction closed, so the commonest spelling of all, `$form = new Form()`, opened under
	 * CONSTRUCTOR_BUILD over an `addComponent` call inside the `if ($parent !== null)` branch that a
	 * zero-argument construction can never reach.
	 */
	public function constructorShape(ClassMethod $fn, string $trackedName, string $siteClassFqcn): ?FormShape
	{
		$sole = self::soleTrackedAssign($fn, $trackedName);
		if ($sole === null || !$sole->expr instanceof New_ || !$sole->expr->class instanceof Name) {
			return null;
		}

		$constructedClass = $this->resolveConstructedClass($sole->expr->class, $siteClassFqcn);
		if ($constructedClass === null) {
			return null;
		}

		return $this->constructedResolver->resolve($constructedClass, count($sole->expr->getArgs()));
	}

	/**
	 * Scope-free equivalent of Scope::resolveName() at a registration site, where the scope's
	 * class equals the site class: self/static resolve to the site class, parent to its
	 * reflected parent (null when parentless), any other name to its NameResolver-resolved
	 * string.
	 */
	private function resolveConstructedClass(Name $name, string $siteClassFqcn): ?string
	{
		$lower = $name->toLowerString();
		if ($lower === 'self' || $lower === 'static') {
			return $siteClassFqcn;
		}

		if ($lower === 'parent') {
			if (!$this->reflectionProvider->hasClass($siteClassFqcn)) {
				return null;
			}

			$parent = $this->reflectionProvider->getClass($siteClassFqcn)->getParentClass();

			return $parent === null ? null : $parent->getName();
		}

		return $name->toString();
	}

	public function declaredReturnFormClass(ClassReflection $classReflection, string $method): ?string
	{
		if (!$classReflection->hasNativeMethod($method)) {
			return null;
		}

		$returnType = $classReflection
			->getNativeMethod($method)
			->getVariants()[0]
			->getReturnType();

		$isFormOrContainer = (new ObjectType(NetteForm::class))->isSuperTypeOf($returnType)->yes()
			|| (new ObjectType(NetteContainer::class))->isSuperTypeOf($returnType)->yes();
		if (!$isFormOrContainer) {
			return null;
		}

		// only a single concrete Form/Container class is the form's contract; a union or a
		// broad IComponent/interface return stays unresolved so the \mixed-guard suppresses it
		$classes = $returnType->getObjectClassNames();

		return count($classes) === 1 ? '\\' . ltrim($classes[0], '\\') : null;
	}

	/**
	 * The one predicate for "this shape names no class", and the reason `\mixed` is not simply a
	 * sentinel nobody may produce: FormShapeAnalyzer::analyzeFormValue() writes it deliberately for
	 * an entry it cannot type at all, and this is what every consumer must read it back through.
	 * Static and shared because it was spelled inline in a third place, and a consumer that spells
	 * it itself is a consumer that can be forgotten - which is exactly how a `\mixed`-classed shape
	 * would reach a type carrier and become an object type over a class that does not exist.
	 *
	 * The pipe-joined form is the SECOND such sentinel and rides the same read-back: FormShapeAnalyzer
	 * writes implode('|', $classes) for an entry whose scope type names more than one class, and
	 * nestedContainerClass() already reads that spelling back inline as unresolved. Measured over the
	 * whole app corpus (0 occurrences) and the fixture suite (9, none of which reach a carrier), so
	 * this clause changes no diagnostic today - it exists because a pseudo-class that only ONE
	 * consumer knows about is exactly the forgotten-consumer shape the paragraph above describes,
	 * and every consumer of this predicate degrades to its declared return class instead, which is a
	 * real class where A|B never is.
	 *
	 * @phpstan-assert-if-false !null $className
	 */
	public static function isClassNameUnresolved(?string $className): bool
	{
		return $className === null
			|| ltrim($className, '\\') === 'mixed'
			|| strpos($className, '|') !== false;
	}

}
