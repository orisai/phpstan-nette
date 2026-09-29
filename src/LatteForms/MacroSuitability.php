<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

use Nette\ComponentModel\Container as ComponentModelContainer;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\HiddenField;
use OriPhpstan\Nette\Latte\Forms\ControlReference;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use function ltrim;

// Whether the macro that names a component is one that component can answer. Every verdict below is
// read off vendor's own compiled output, never guessed - the three generations of the forms bridge
// (Latte 2 FormMacros, nette/forms < 3.3 FormsExtension, nette/forms 3.3 FormsExtension) differ only
// in how they reach the control, so one verdict holds for all of them:
//
// - {input X} compiles to `<X>->getControl()` (or ->getControlPart(...) with a ':'-part), {label X} /
//   n:label to `if ($l = <X>->getLabel()) echo $l` (`($ʟ_label = <X>->getLabel())?->startTag()` on
//   Latte 3), {inputError X} to `<X>->getError()`, and <el n:name="X"> to
//   `<X>->getControlPart()->attributes()`, where <X> is `end($formsStack)[X]` (Latte 2),
//   `Runtime::item(X, $this->global)` (nette/forms 3.1.7-3.2) or `$this->global->forms->get(X)` (3.3).
//   Nette\Forms\Container declares none of those four methods and its __call() falls through to
//   Nette\SmartObject's strict one, so any of them on a container is a runtime error, not a no-op;
//   the 3.3 runtime's get() throws on a container before the call is even made.
// - {formContainer X} / n:formContainer pushes the component onto $formsStack (3.3: `forms->begin(
//   forms->get(X, Container::class))`), and every reference under it offsets that component. A
//   Nette\Forms\Controls\BaseControl is not an ArrayAccess, so the first reference inside fatals
//   (3.3: get() rejects it up front); a body with no reference in it is inert markup. Reported
//   either way: the macro's only purpose is to scope inner references, and there is no component
//   that is both a control and a container to scope them against.
// - Button::getLabel() and HiddenField::getLabel() are vendor overrides whose whole body is
//   `return null`, commented "Bypasses label generation" - so {label} on one renders NOTHING at all.
//   The declaring class is what is asked, not the ancestry: a project subclass that overrides
//   getLabel() again gets its label back, and must not be reported.
final class MacroSuitability
{

	public const MISMATCH_CONTAINER_AS_CONTROL = 'containerAsControl';

	public const MISMATCH_CONTROL_AS_CONTAINER = 'controlAsContainer';

	public const MISMATCH_NO_LABEL = 'noLabel';

	private const LABEL_METHOD = 'getLabel';

	private ReflectionProvider $reflectionProvider;

	public function __construct(ReflectionProvider $reflectionProvider)
	{
		$this->reflectionProvider = $reflectionProvider;
	}

	/**
	 * @param ControlReference::KIND_* $referenceKind
	 * @return self::MISMATCH_*|null
	 */
	public function mismatch(string $referenceKind, ComponentIdentity $identity): ?string
	{
		$isContainer = $identity->getKind() === ComponentIdentity::KIND_CONTAINER;

		if ($referenceKind === ControlReference::KIND_CONTAINER) {
			return !$isContainer && $this->everyClassIsDefinitelyNoContainer($identity)
				? self::MISMATCH_CONTROL_AS_CONTAINER
				: null;
		}

		if ($isContainer) {
			return self::MISMATCH_CONTAINER_AS_CONTROL;
		}

		return $referenceKind === ControlReference::KIND_LABEL && $this->everyClassRendersNoLabel($identity)
			? self::MISMATCH_NO_LABEL
			: null;
	}

	// The recorded class is the one the builder call yields, which is equal to or WIDER than the
	// runtime one - so only a definite NO transfers to the runtime class. A maybe (an interface, an
	// unplaceable name, a class that could be subclassed into a container) proves nothing and the
	// whole reference goes silent, which is also what a null class list does.
	private function everyClassIsDefinitelyNoContainer(ComponentIdentity $identity): bool
	{
		$classes = $identity->getClasses();
		if ($classes === null || $classes === []) {
			return false;
		}

		$container = new ObjectType(ComponentModelContainer::class);
		foreach ($classes as $class) {
			if (!$container->isSuperTypeOf(new ObjectType(ltrim($class, '\\')))->no()) {
				return false;
			}
		}

		return true;
	}

	private function everyClassRendersNoLabel(ComponentIdentity $identity): bool
	{
		$classes = $identity->getClasses();
		if ($classes === null || $classes === []) {
			return false;
		}

		foreach ($classes as $class) {
			if (!$this->rendersNoLabel(ltrim($class, '\\'))) {
				return false;
			}
		}

		return true;
	}

	private function rendersNoLabel(string $class): bool
	{
		if (!$this->reflectionProvider->hasClass($class)) {
			return false;
		}

		$reflection = $this->reflectionProvider->getClass($class);
		if (!$reflection->hasNativeMethod(self::LABEL_METHOD)) {
			return false;
		}

		$declaring = $reflection->getNativeMethod(self::LABEL_METHOD)->getDeclaringClass()->getName();

		return $declaring === Button::class || $declaring === HiddenField::class;
	}

}
