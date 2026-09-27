<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use Nette\ComponentModel\IComponent;
use Nette\Forms\Container as NetteContainer;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\ChoiceControl;
use Nette\Forms\Controls\ColorPicker;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\ImageButton;
use Nette\Forms\Controls\MultiChoiceControl;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Controls\TextArea;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Controls\UploadControl;
use OriPhpstan\Nette\Forms\Analyzer\CalleeShapeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Component\AnalysedPaths;
use OriPhpstan\Nette\Forms\Graph\ContainerRegistrationDetector;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use function count;

final class NetteEffectiveControlValueTypeResolver implements ControlValueTypeResolver
{

	private const REGISTERS_BY_ARGUMENT_ZERO = 'argument_zero';

	private const REGISTERS_ELSEWHERE = 'elsewhere';

	private const REGISTERS_NOTHING = 'nothing';

	private const REGISTERS_UNREADABLE = 'unreadable';

	private const SET_SPEC_LADDER = [
		[TextArea::class, 'text'],
		[Checkbox::class, 'checkbox'],
		[HiddenField::class, 'hidden'],
		[MultiChoiceControl::class, 'multichoice'],
		[ChoiceControl::class, 'choice'],
		[UploadControl::class, 'upload'],
		[DateTimeControl::class, 'datetime'],
		[ColorPicker::class, 'color'],
		[TextInput::class, 'text'],
	];

	private ControlAnnotationValueTypeReader $annotationReader;

	private CalleeShapeResolver $callees;

	/**
	 * Wired from the config's declared paths (`%paths%`), never PHPStan's CLI-narrowed
	 * `%analysedPaths%` and never a `vendor/` spelling: which code is OURS has to be a property of the
	 * project, so a single-file/IDE run draws the line exactly where a whole-project run does. Every
	 * construction of this resolver shares the one FormShapeCache, so they must also share this list —
	 * two of them disagreeing would let one poison the other's entries.
	 */
	private AnalysedPaths $analysedPaths;

	private ContainerRegistrationDetector $detector;

	/** @var array<string, bool> */
	private array $conventionMemo = [];

	/** @var array<string, self::REGISTERS_*> */
	private array $registrationMemo = [];

	/**
	 * @param list<string> $analysedPaths
	 */
	public function __construct(
		ControlAnnotationValueTypeReader $annotationReader,
		CalleeShapeResolver $callees,
		array $analysedPaths = []
	)
	{
		$this->annotationReader = $annotationReader;
		$this->callees = $callees;
		$this->analysedPaths = new AnalysedPaths($analysedPaths);
		$this->detector = new ContainerRegistrationDetector();
	}

	public function resolve(
		string $methodName,
		Type $receiverType,
		Expr $call,
		Scope $scope,
		bool $trustReceiverReflection = true
	): ControlValueResolution
	{
		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf($receiverType)->yes()) {
			return new ControlValueResolution(ControlValueResolution::KIND_UNKNOWN_TYPE, null, []);
		}

		// A declared contract beats every reading below it — the vendor stub, the reflected
		// return type and the body read alike. The class it names goes through resolveControlType()
		// so an annotated method lands on exactly the ladder `$form['x'] = new C` already lands on:
		// a container composes, a @form-replicator container replicates, a control's own
		// @form-read-type types it. Only the FIRST occurrence answers here; a repeatable tag's
		// further occurrences are separate registrations, resolved by the summary factory.
		$specs = $trustReceiverReflection ? $this->addsSpecs($methodName, $receiverType, $scope) : [];
		if (isset($specs[0]) && $specs[0]->getControlClass() !== null) {
			return $this->resolveControlType(new ObjectType($specs[0]->getControlClass()));
		}

		// The vendor factories. The control CLASS is Nette\Forms\Container's own declared return type
		// for the method, read live — nothing restates it — and the value type and write spec come
		// from the catalog, which is method-keyed because they genuinely are (addInteger() and
		// addText() are both TextInput). Carrying a catalog entry is also what MAKES a vendor method
		// a value factory: addContainer(), addSubmit() and addButton() return components too and fall
		// through to the branches below, exactly as they did when this was a switch.
		$vendorClass = $this->annotationReader->controlClassForAddMethod($methodName);
		if ($vendorClass !== null) {
			$valueType = $this->annotationReader->readTypeForAddMethod($methodName);
			if ($valueType !== null) {
				return new ControlValueResolution(
					ControlValueResolution::KIND_VALUE,
					$valueType,
					[],
					$vendorClass,
					false,
					$this->annotationReader->writeSpecForAddMethod($methodName) ?? $this->ladderSetSpec($vendorClass),
				);
			}
		}

		// With $trustReceiverReflection off the receiver — and hence the call's reflected
		// return type — may follow a stale same-named outer binding, so only method-NAME-derived
		// resolutions apply; receiver-reflected reads degrade to an unknown-typed slot.
		$typeOnlyClass = $trustReceiverReflection
			? $this->typeOnlyClass($scope->getType($call)) ?? $this->bodyReturnedControlClass(
				$methodName,
				$receiverType,
				$scope,
			)
			: null;

		$replicatorMeta = $trustReceiverReflection
			? $this->replicatorMeta($methodName, $typeOnlyClass, $receiverType, $scope)
			: $this->annotationReader->replicatorMetaForAddMethod($methodName);
		if ($replicatorMeta !== null) {
			return new ControlValueResolution(
				ControlValueResolution::KIND_REPLICATOR,
				null,
				[],
				$typeOnlyClass ?? $replicatorMeta->getContainerClass(),
				false,
				null,
				false,
				$replicatorMeta->getFactoryArgPosition(),
			);
		}

		switch ($methodName) {
			case 'addContainer':
				return new ControlValueResolution(ControlValueResolution::KIND_CONTAINER, null, []);
			case 'addSubmit':
			case 'addImageButton':
			case 'addImage':
			case 'addProtection':
			case 'addReCaptcha':
				return new ControlValueResolution(
					ControlValueResolution::KIND_OMITTED,
					null,
					[],
					$typeOnlyClass ?? ($trustReceiverReflection
						? $this->reflectedControlClass($methodName, $receiverType, $scope)
						: null),
				);
			default:
				if (!$trustReceiverReflection) {
					return new ControlValueResolution(
						ControlValueResolution::KIND_UNKNOWN_TYPE,
						null,
						[UnknownReason::EXTENSION_METHOD],
						null,
					);
				}

				if ($this->returnsNonComponent($methodName, $receiverType, $scope)) {
					return $this->nonComponentReturnResolution($methodName, $receiverType, $scope);
				}

				$controlClass = $typeOnlyClass ?? $this->reflectedControlClass($methodName, $receiverType, $scope);
				$custom = $controlClass !== null ? $this->customControlResolution($controlClass) : null;
				if ($custom !== null) {
					return $custom;
				}

				return new ControlValueResolution(
					ControlValueResolution::KIND_UNKNOWN_TYPE,
					null,
					[UnknownReason::EXTENSION_METHOD],
					$typeOnlyClass,
				);
		}
	}

	/**
	 * The valid @form-adds occurrences of the method this call dispatches to, in docblock order.
	 *
	 * Resolved through the RECEIVER rather than a name lookup, so an override's own tag wins and its
	 * own parameter list is what the name operand is resolved against. The receiver having already
	 * been gated to a Nette\Forms\Container by resolve(), the reader's own declaring-class gate is
	 * what keeps a tag on Nette\ComponentModel\Container (an ancestor that is not a form container)
	 * out of the model.
	 *
	 * @return list<FormAddsSpec>
	 */
	public function addsSpecs(string $methodName, Type $receiverType, Scope $scope): array
	{
		if (!$receiverType->hasMethod($methodName)->yes()) {
			return [];
		}

		return $this->annotationReader->addsSpecsForMethod($receiverType->getMethod($methodName, $scope));
	}

	/**
	 * Whether a call's registration is one the walk may not read a name off at all.
	 *
	 * The walk's convention is one component named by the call's first argument, and for our own code
	 * it does not matter whether that is true — the body is walked, so a helper naming its component
	 * from some other parameter resolves anyway. Outside the analysed paths there is no walk, the tag
	 * is the only channel, and a body refuting the convention means the name argument 0 carries is
	 * NOT the component's: reading it would register a component under the label, the caption or the
	 * error message, and prove the real one absent.
	 *
	 * Everything it cannot establish leaves the convention standing — a body it cannot see, a
	 * declaration inside the analysed paths, or a body registering nothing this recognises. An
	 * undetected registration in vendor code therefore still closes, which is the cost of not guessing.
	 *
	 * The @form-adds gate is the CALLER's: an annotated method resolves its name properly and never
	 * needs this asked, and the caller has the specs in hand already.
	 */
	public function bodyRefutesNameConvention(string $methodName, Type $receiverType, Scope $scope): bool
	{
		if (!$this->analysedPaths->isConfigured() || !$receiverType->hasMethod($methodName)->yes()) {
			return false;
		}

		$declaringClass = $receiverType->getMethod($methodName, $scope)->getDeclaringClass();

		// The cheap half of the gate, and the reason this can be asked at every registering call site:
		// a declaring class whose own file is ours is answered without parsing anything. Only a vendor
		// receiver reaches the body read, and that read is memoized per declaration. A vendor class
		// composing a PROJECT trait is answered by the file the located method really lives in, below;
		// the reverse — our class composing a vendor trait — is left standing here, which keeps the
		// pre-gate conservative rather than admitting a body it should have refused.
		$file = $declaringClass->getFileName();
		if ($file === null || $this->analysedPaths->isAnalysed($file)) {
			return false;
		}

		$key = $declaringClass->getName() . '::' . $methodName;

		return $this->conventionMemo[$key] ??= $this->readRegistrarBody($declaringClass->getName(), $methodName);
	}

	private function readRegistrarBody(string $declaringClass, string $methodName): bool
	{
		$located = $this->callees->visibleMethodNodeOf($declaringClass, $methodName);
		if ($located === null) {
			return false;
		}

		[$node, $file] = $located;
		if ($this->analysedPaths->isAnalysed($file)) {
			return false;
		}

		$registrations = $this->detector->registrations(
			$node->stmts,
			ContainerRegistrationDetector::parameterIndexes($node),
		);

		return $registrations !== [] && !ContainerRegistrationDetector::matchesFirstArgumentConvention($registrations);
	}

	private function replicatorMeta(
		string $methodName,
		?string $typeOnlyClass,
		Type $receiverType,
		Scope $scope
	): ?ReplicatorMeta
	{
		$byName = $this->annotationReader->replicatorMetaForAddMethod($methodName);
		if ($byName !== null) {
			return $byName;
		}

		$returnClass = $typeOnlyClass ?? $this->reflectedControlClass($methodName, $receiverType, $scope);

		return $returnClass !== null ? $this->annotationReader->replicatorMetaForClass($returnClass) : null;
	}

	/**
	 * What a Container-declared registration spelling contributes once its DECLARATION has said it
	 * returns no component.
	 *
	 * The declaration alone used to decide this, and deciding it alone is how a registration becomes an
	 * absence: `addByOffset(string $name): void` registers a component and returns nothing, so a
	 * return-type-only reading dropped the name from a shape that then stayed CLOSED and proved the
	 * component missing. The name is the same heuristic one step over — a method spelled add* is assumed
	 * to register — so neither half may answer on its own.
	 *
	 * The body is the evidence, read by the same detector the annotated/vendor split is decided from:
	 *
	 * - it registers one component named by argument 0 → the component is recorded, with no value type,
	 *   because a void return says nothing about what was registered;
	 * - it registers nothing → nothing is recorded and the shape stays closed, which is what makes
	 *   Nette's own addError()/addGroup() and any project method shaped like them inert without a name
	 *   blocklist saying so;
	 * - it registers under a name argument 0 does not carry, or the body cannot be read at all → the
	 *   shape OPENS. Reading argument 0 there would register the error message or the caption and prove
	 *   the real component absent, and an unreadable body proves nothing in either direction.
	 */
	private function nonComponentReturnResolution(
		string $methodName,
		Type $receiverType,
		Scope $scope
	): ControlValueResolution
	{
		$registration = $this->bodyRegistration($methodName, $receiverType, $scope);

		if ($registration === self::REGISTERS_BY_ARGUMENT_ZERO) {
			return new ControlValueResolution(
				ControlValueResolution::KIND_UNKNOWN_TYPE,
				null,
				[UnknownReason::EXTENSION_METHOD],
				null,
			);
		}

		if ($registration === self::REGISTERS_NOTHING) {
			return new ControlValueResolution(ControlValueResolution::KIND_OMITTED, null, []);
		}

		return new ControlValueResolution(
			ControlValueResolution::KIND_OMITTED,
			null,
			[UnknownReason::UNANNOTATED_REGISTRAR],
		);
	}

	/**
	 * @return self::REGISTERS_*
	 */
	private function bodyRegistration(string $methodName, Type $receiverType, Scope $scope): string
	{
		if (!$receiverType->hasMethod($methodName)->yes()) {
			return self::REGISTERS_UNREADABLE;
		}

		$declaringClass = $receiverType->getMethod($methodName, $scope)->getDeclaringClass()->getName();
		$key = $declaringClass . '::' . $methodName;

		return $this->registrationMemo[$key] ??= $this->readRegistrations($declaringClass, $methodName);
	}

	/**
	 * @return self::REGISTERS_*
	 */
	private function readRegistrations(string $declaringClass, string $methodName): string
	{
		$located = $this->callees->visibleMethodNodeOf($declaringClass, $methodName);
		if ($located === null) {
			return self::REGISTERS_UNREADABLE;
		}

		$registrations = $this->detector->registrations(
			$located[0]->stmts,
			ContainerRegistrationDetector::parameterIndexes($located[0]),
		);

		if ($registrations === []) {
			return self::REGISTERS_NOTHING;
		}

		return ContainerRegistrationDetector::matchesFirstArgumentConvention($registrations)
			? self::REGISTERS_BY_ARGUMENT_ZERO
			: self::REGISTERS_ELSEWHERE;
	}

	private function returnsNonComponent(string $methodName, Type $receiverType, Scope $scope): bool
	{
		if (!$receiverType->hasMethod($methodName)->yes()) {
			return false;
		}

		$returnType = $receiverType->getMethod($methodName, $scope)->getVariants()[0]->getReturnType();

		$objectClasses = $returnType->getObjectClassNames();
		if ($objectClasses !== []) {
			foreach ($objectClasses as $class) {
				if (!(new ObjectType($class))->isInstanceOf(IComponent::class)->no()) {
					return false;
				}
			}

			return true;
		}

		return $returnType->isVoid()->yes()
			|| $returnType->isNull()->yes()
			|| $returnType->isScalar()->yes();
	}

	private function reflectedControlClass(string $methodName, Type $receiverType, Scope $scope): ?string
	{
		if (!$receiverType->hasMethod($methodName)->yes()) {
			return null;
		}

		return $this->typeOnlyClass(
			$receiverType->getMethod($methodName, $scope)->getVariants()[0]->getReturnType(),
		);
	}

	/**
	 * The control class of an `add*` helper that declares no return type, read off its own body.
	 *
	 * PHPStan reflects the DECLARED return and infers nothing from a body, so `public function
	 * addThing(string $name) { $c = new TextInput(); $this[$name] = $c; return $c; }` answers `mixed`
	 * — and the slot the walk records is present but has no class, which cascades `mixed` onto every
	 * read of that control. Nothing else in the walk has the class either: presence comes from the
	 * `add*` NAME, not from reading the body, so this is the first time the body is looked at.
	 *
	 * A method that names ANY class in its return type — natively or in a docblock, precisely or
	 * widened to a base control — is not read: a declaration is a statement, and widening one is a
	 * deliberate act of imprecision rather than an omission. Only the total absence of a declared
	 * class reaches the body, and a body proving nothing there — disagreeing returns, a fall-through
	 * path, a return this cannot read, a vendor body the parser stripped — leaves the slot exactly as
	 * opaque as it was. That last case is the load-bearing one: a genuine extension method
	 * (`addRemoveOnClick`, registered at runtime and having no native declaration at all) resolves to
	 * nothing here, so its `extension_method` degradation is untouched.
	 */
	private function bodyReturnedControlClass(string $methodName, Type $receiverType, Scope $scope): ?string
	{
		if (!$receiverType->hasMethod($methodName)->yes()) {
			return null;
		}

		$method = $receiverType->getMethod($methodName, $scope);
		if ($method->getVariants()[0]->getReturnType()->getObjectClassNames() !== []) {
			return null;
		}

		$declaring = $method->getDeclaringClass()->getName();
		$returned = $this->callees->returnedObjectClass($declaring, $methodName);

		return $returned !== null ? $this->typeOnlyClass(new ObjectType($returned)) : null;
	}

	private function typeOnlyClass(Type $type): ?string
	{
		return ComponentClassName::of($type);
	}

	public function resolveControlType(Type $controlType): ControlValueResolution
	{
		// A replicator is also a Container, so it must be recognised before the plain-container
		// branch — keeping $form['x'] = new Rep(...) and addComponent(new Rep(...)) equal to add*().
		$replicatorMeta = $this->annotationReader->replicatorMetaForContainerType($controlType);
		if ($replicatorMeta !== null) {
			return new ControlValueResolution(
				ControlValueResolution::KIND_REPLICATOR,
				null,
				[],
				$this->typeOnlyClass($controlType) ?? $replicatorMeta->getContainerClass(),
				false,
				null,
				false,
				$replicatorMeta->getFactoryArgPosition(),
			);
		}

		if ((new ObjectType(NetteContainer::class))->isSuperTypeOf($controlType)->yes()) {
			return new ControlValueResolution(ControlValueResolution::KIND_CONTAINER, null, []);
		}

		// Order-sensitive: more specific control bases must precede their parents (TextArea & buttons before TextInput; MultiChoiceControl before ChoiceControl).
		foreach (self::SET_SPEC_LADDER as [$base, $setSpec]) {
			if (!(new ObjectType($base))->isSuperTypeOf($controlType)->yes()) {
				continue;
			}

			$concrete = $this->concreteClassName($controlType, $base);
			$valueType = $this->annotationReader->readTypeForClass($concrete);
			if ($valueType === null) {
				break;
			}

			return new ControlValueResolution(
				ControlValueResolution::KIND_VALUE,
				$valueType,
				[],
				$concrete,
				false,
				$setSpec,
			);
		}

		if (
			(new ObjectType(SubmitButton::class))->isSuperTypeOf($controlType)->yes()
			|| (new ObjectType(ImageButton::class))->isSuperTypeOf($controlType)->yes()
		) {
			return new ControlValueResolution(
				ControlValueResolution::KIND_OMITTED,
				null,
				[],
				$this->typeOnlyClass($controlType),
			);
		}

		$unknownClass = $this->typeOnlyClass($controlType);
		$custom = $unknownClass !== null ? $this->customControlResolution($unknownClass) : null;
		if ($custom !== null) {
			return $custom;
		}

		return new ControlValueResolution(
			ControlValueResolution::KIND_UNKNOWN_TYPE,
			null,
			[],
			$unknownClass,
		);
	}

	private function customControlResolution(string $controlClass): ?ControlValueResolution
	{
		$valueType = $this->annotationReader->readTypeForClass($controlClass);
		if ($valueType === null) {
			return null;
		}

		$writeType = $this->annotationReader->writeTypeForClass($controlClass);

		return new ControlValueResolution(
			ControlValueResolution::KIND_VALUE,
			$valueType,
			[],
			$controlClass,
			false,
			$writeType !== null ? ControlAcceptedTypeResolver::CUSTOM_SPEC_PREFIX . $writeType : null,
		);
	}

	public function choiceModel(string $controlClass): ?ChoiceModel
	{
		return $this->annotationReader->choiceModelForClass($controlClass);
	}

	public function modifierEffect(string $controlClass, string $methodName): ?string
	{
		return $this->annotationReader->modifierEffectForMethod($controlClass, $methodName);
	}

	public function isFormDisabler(string $containerClass, string $methodName): bool
	{
		return $this->annotationReader->isFormDisabler($containerClass, $methodName);
	}

	public function declaresAdds(string $containerClass, string $methodName): bool
	{
		return $this->annotationReader->declaresAddsOn($containerClass, $methodName);
	}

	public function isReadByArgModifier(string $controlClass, string $methodName): bool
	{
		return $this->annotationReader->isReadByArgModifier($controlClass, $methodName);
	}

	public function readTypeByArg(string $controlClass, string $methodName, ?string $argLiteral): ?Type
	{
		return $this->annotationReader->readTypeByArg($controlClass, $methodName, $argLiteral);
	}

	public function ruleCastType(string $ruleIdentifier): ?RuleCastType
	{
		return $this->annotationReader->ruleCastType($ruleIdentifier);
	}

	public function getReflectionProvider(): ReflectionProvider
	{
		return $this->annotationReader->getReflectionProvider();
	}

	/**
	 * The same rung resolveControlType() would land $controlClass on, without its value read: the
	 * write-side spec a control class implies. A vendor add* method whose control accepts something
	 * narrower than its class implies overrides it with @form-write-spec.
	 */
	private function ladderSetSpec(string $controlClass): ?string
	{
		$controlType = new ObjectType($controlClass);
		foreach (self::SET_SPEC_LADDER as [$base, $setSpec]) {
			if ((new ObjectType($base))->isSuperTypeOf($controlType)->yes()) {
				return $setSpec;
			}
		}

		return null;
	}

	private function concreteClassName(Type $controlType, string $base): string
	{
		$classes = $controlType->getObjectClassNames();

		return count($classes) === 1 ? $classes[0] : $base;
	}

}
