<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Application\UI\Form as UiForm;
use Nette\Forms\Form as NetteForm;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use PHPStan\Reflection\ReflectionProvider;
use function in_array;
use function ltrim;

/**
 * Classifies a `new X()` the same way for every caller and every spelling — a factory's
 * `return new X()`, a rebind the analyzer absorbs, a vendor method's return arm, a
 * `$form = new X()` sole binding: a class without a constructor, or a bare `new Form()` on a
 * Nette base constructor, adds no controls and is provably empty; any other constructor resolves
 * through ConstructorFormShapeResolver. An unknown class or an unlocatable constructor returns
 * null — never an empty-closed shape — so the caller's rebind marker survives an origin it could
 * not read. Every caller must come through here rather than through ConstructorFormShapeResolver
 * directly, which is what the carve-outs above that delegation are: a caller that skips them gets
 * a different answer for the same class.
 */
final class ConstructedFormShapeResolver
{

	private ReflectionProvider $reflectionProvider;

	private ConstructorFormShapeResolver $constructorResolver;

	public function __construct(
		ReflectionProvider $reflectionProvider,
		ConstructorFormShapeResolver $constructorResolver
	)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->constructorResolver = $constructorResolver;
	}

	public function resolve(string $fqcn, int $ctorArgCount): ?FormShape
	{
		$fqcn = ltrim($fqcn, '\\');
		if (!$this->reflectionProvider->hasClass($fqcn)) {
			return null;
		}

		$classReflection = $this->reflectionProvider->getClass($fqcn);
		if (!$classReflection->hasConstructor()) {
			return FormShape::empty('\\' . $fqcn);
		}

		// A named-class carve-out standing in for an argument the constructor never receives: the only
		// component work either Nette base constructor does sits under a guard a zero-argument
		// construction can never pass — `Application\UI\Form` runs `$parent->addComponent($this, $name)`
		// under `if ($parent !== null)`, `Forms\Form` builds its tracker under `if ($name !== null)`.
		//
		// Modelling those branches as unreachable from the call site's own arguments derives the FIRST
		// entry and only the first. Measured, not assumed: with the branch skipped, `new UI\Form()`
		// closes empty exactly as the list makes it, and `new Forms\Form()` still OPENS, because one
		// statement is left standing — `$this->monitor(self::class, function (): void { throw …; })` —
		// which ConstructorFormShapeResolver::isInertStatement() refuses on four INDEPENDENT grounds: a
		// Closure in the subtree, a New_ in the subtree, `monitor` being in MUTATING_METHOD_NAMES, and
		// `monitor` not being an allowlisted inherited accessor. Undoing all four means rewriting a
		// deny-by-default predicate that guards every analysed constructor, which is a much larger
		// findings-changing change than the list it would retire, and in the direction that invents.
		//
		// So the derivation retires half a list and regresses the other half from empty-and-closed to
		// open, which is strictly worse for that class; the list stands until the predicate is rebuilt.
		// It must not GROW: a third entry is a signal to do that work, not to extend the special case.
		// Both entries are pinned by ConstructedLocal, the second one solely there — no call site in
		// this project's corpus reaches it (77 of 85 constructions here are the first entry).
		$ctorDeclaring = $classReflection->getConstructor()->getDeclaringClass()->getName();
		if ($ctorArgCount === 0 && in_array($ctorDeclaring, [NetteForm::class, UiForm::class], true)) {
			return FormShape::empty('\\' . $fqcn);
		}

		return $this->constructorResolver->resolve($ctorDeclaring);
	}

}
