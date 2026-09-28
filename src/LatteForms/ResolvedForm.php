<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use function array_intersect;
use function array_key_exists;
use function array_pop;
use function in_array;

// One (renderer class, form component) pair a template's {form X} scope resolves to, together with
// the Forms shape that component builds.
//
// THE CERTAINTY GATE lives in isComplete(), applied by isClosed() at the root and re-applied by
// lookup() at every container it descends through. It answers one question only: is the shape's NAME
// SET complete? Slot presence is a different axis and deliberately not part of it - a MAYBE slot is a
// conditionally attached component whose name IS known, so it can never be reported absent and hides
// no other name. Container presence is the one place presence does enter, as a path property rather
// than a name-set one: a hop through a container that is not definitely attached is not a closed path.
final class ResolvedForm
{

	public const LOOKUP_PRESENT = 'present';

	public const LOOKUP_ABSENT = 'absent';

	public const LOOKUP_UNRESOLVED = 'unresolved';

	private string $rendererClass;

	private string $formName;

	private FormShape $shape;

	/** @var callable(string|null, string): bool */
	private $externallyMutated;

	/**
	 * @param callable(string|null, string): bool $externallyMutated
	 */
	public function __construct(
		string $rendererClass,
		string $formName,
		FormShape $shape,
		callable $externallyMutated
	)
	{
		$this->rendererClass = $rendererClass;
		$this->formName = $formName;
		$this->shape = $shape;
		$this->externallyMutated = $externallyMutated;
	}

	public function getRendererClass(): string
	{
		return $this->rendererClass;
	}

	public function getFormName(): string
	{
		return $this->formName;
	}

	public function getShape(): FormShape
	{
		return $this->shape;
	}

	public function isMutatedExternallyAnywhere(): bool
	{
		return ($this->externallyMutated)($this->rendererClass, $this->formName)
			|| $this->containerMutatedExternally($this->shape);
	}

	public function shapeForTyping(): FormShape
	{
		$shape = $this->gateContainers($this->shape);
		if ($this->isClosed() || self::hasOwnUnknown($shape)) {
			return $shape;
		}

		return $shape->withUnknownReason(UnknownReason::UNPROVEN_COMPLETE);
	}

	private function gateContainers(FormShape $shape): FormShape
	{
		$owner = $shape->getClassName();
		foreach ($shape->getContainers() as $name => $container) {
			$gated = $this->gateContainers($container);
			if (!self::hasOwnUnknown($gated) && !$this->isComplete($gated, $owner, $name)) {
				$gated = $gated->withUnknownReason(UnknownReason::UNPROVEN_COMPLETE);
			}

			if ($gated !== $container) {
				$shape = $shape->withContainer($name, $gated);
			}
		}

		return $shape;
	}

	private function containerMutatedExternally(FormShape $shape): bool
	{
		$owner = $shape->getClassName();
		foreach ($shape->getContainers() as $name => $container) {
			if (($this->externallyMutated)($owner, $name) || $this->containerMutatedExternally($container)) {
				return true;
			}
		}

		return false;
	}

	private static function hasOwnUnknown(FormShape $shape): bool
	{
		return $shape->getUnknown()->hasUnknown();
	}

	// Whether this form's OWN name set is complete. A nested container carries its own UnknownInfo
	// which the root shape never reports, so this answers for the root level only - lookup() is the
	// absence oracle, re-applying the same predicate at every container it descends into.
	public function isClosed(): bool
	{
		return $this->isComplete($this->shape, $this->rendererClass, $this->formName);
	}

	// Whether a control reference names a component of this form. The two sources of nesting
	// concatenate: the lexical {formContainer} chain first, then the '-' segments of the name, which
	// Nette\ComponentModel\Container::getComponent() explodes at runtime. All but the last segment
	// are container hops.

	/**
	 * @param list<string> $containerPath
	 * @return self::LOOKUP_*
	 */
	public function lookup(array $containerPath, string $name): string
	{
		$segments = [...$containerPath, ...ComponentPath::split($name)];
		$leaf = array_pop($segments);

		$current = $this->shape;

		// The (owner class, component name) pair the current shape was reached under - what the
		// external-mutation question is asked about, and it changes at every hop.
		$owner = $this->rendererClass;
		$component = $this->formName;

		// Absence needs EVERY shape on the path to be complete, not only the one holding the leaf: a
		// root-level container_reference records a container the analyser handed off WITHOUT being
		// able to say which, so any child below it may be missing components of its own. Membership
		// stays trustworthy either way - a name an incomplete shape does list really is there.
		$trusted = true;

		foreach ($segments as $segment) {
			$containers = $current->getContainers();
			if (isset($containers[$segment])) {
				if (($current->getContainerPresence()[$segment] ?? Certainty::HAPPENS) !== Certainty::HAPPENS) {
					return self::LOOKUP_UNRESOLVED;
				}

				$trusted = $trusted && $this->isComplete($current, $owner, $component);
				$owner = $current->getClassName();
				$component = $segment;
				$current = $containers[$segment];

				continue;
			}

			// A known name that is not a traversable container - a control, a replicator (indexed by
			// replica number at runtime) or a component with no shape. Whether the macro may address
			// it that way is type-aware checking, deliberately out of v1.
			if (self::has($current, $segment)) {
				return self::LOOKUP_UNRESOLVED;
			}

			return $trusted && $this->isComplete($current, $owner, $component)
				? self::LOOKUP_ABSENT
				: self::LOOKUP_UNRESOLVED;
		}

		if (self::has($current, $leaf)) {
			return self::LOOKUP_PRESENT;
		}

		return $trusted && $this->isComplete($current, $owner, $component)
			? self::LOOKUP_ABSENT
			: self::LOOKUP_UNRESOLVED;
	}

	// WHAT the reference names, for the consumer that asks whether the macro fits the component
	// rather than whether the component exists. Null whenever that question has no definite answer:
	// an unresolvable hop, a name the shape does not list (which is lookup()'s question, not this
	// one), a name two branches disagree about, or an identity the gate below refuses to trust.
	//
	// THE GATE IS A DIFFERENT ONE FROM isComplete(), on purpose. Absence needs the shape's name set
	// to be COMPLETE - one unenumerated build step and a missing name proves nothing. Identity needs
	// only that a name the shape DOES list still means what it says, which no amount of unenumerated
	// ADDING can change: Container::addComponent() throws on a duplicate name, so a step the walk
	// could not read cannot rebind a name the walk did read. What can is a step that LOST one, and
	// the Forms extension already names that set - UnknownReason::LOST_FIELD_UNKNOWN_REASONS, the
	// constant its own does-not-exist rule reads, plus the unresolved-origin reason that rule adds
	// inline - together with the external mutation the same rule's gate asks the index about
	// (removeComponent() reaching in from outside the builder is a rebind like any other).
	//
	// The consequence is deliberate and is the whole reach story of this check: a form the analyser
	// could not finish enumerating still answers what its known components ARE.

	/**
	 * @param list<string> $containerPath
	 */
	public function identify(array $containerPath, string $name): ?ComponentIdentity
	{
		$segments = [...$containerPath, ...ComponentPath::split($name)];
		$leaf = array_pop($segments);

		$current = $this->shape;
		$owner = $this->rendererClass;
		$component = $this->formName;

		foreach ($segments as $segment) {
			$containers = $current->getContainers();
			if (
				!isset($containers[$segment])
				|| isset($current->getSlots()[$segment])
				|| ($current->getContainerPresence()[$segment] ?? Certainty::HAPPENS) !== Certainty::HAPPENS
				|| !$this->identityKnown($current, $owner, $component)
			) {
				return null;
			}

			$owner = $current->getClassName();
			$component = $segment;
			$current = $containers[$segment];
		}

		return $this->identityKnown($current, $owner, $component)
			? self::identityOf($current, $leaf)
			: null;
	}

	private static function identityOf(FormShape $shape, string $name): ?ComponentIdentity
	{
		$isContainer = array_key_exists($name, $shape->getContainers())
			|| array_key_exists($name, $shape->getReplicators());
		$isSlot = array_key_exists($name, $shape->getSlots());

		// Two branches that attached the same name as different things - the one case where the
		// shape carries both channels for one name, and the one case where its kind is not knowable.
		if ($isContainer && $isSlot) {
			return null;
		}

		$containers = $shape->getContainers();
		$recordedClass = $shape->getComponentTypes()[$name] ?? null;

		if ($isContainer) {
			// A replicator carries no shape of its own to read a class off, only the component-type
			// channel; a container carries both, and its own is the narrower one.
			$class = (isset($containers[$name]) ? $containers[$name]->getClassName() : null) ?? $recordedClass;

			return new ComponentIdentity(
				ComponentIdentity::KIND_CONTAINER,
				$class === null ? null : [$class],
			);
		}

		if (!$isSlot) {
			return $recordedClass === null
				? null
				: new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [$recordedClass]);
		}

		// A slot's own control classes first (an add* whose control the catalog placed), then the
		// component-type channel, which is the ONLY class evidence a type-opaque slot ever carries.
		$classes = $shape->getSlots()[$name]->getControlClasses();
		if ($classes === null && $recordedClass !== null) {
			$classes = [$recordedClass];
		}

		return new ComponentIdentity(ComponentIdentity::KIND_CONTROL, $classes);
	}

	private function identityKnown(FormShape $shape, ?string $ownerClass, string $componentName): bool
	{
		$reasons = $shape->getUnknown()->getReasons();

		if (
			array_intersect(UnknownReason::LOST_FIELD_UNKNOWN_REASONS, $reasons) !== []
			|| in_array(UnknownReason::UNRESOLVED_ORIGIN, $reasons, true)
		) {
			return false;
		}

		return !($this->externallyMutated)($ownerClass, $componentName);
	}

	// The certainty gate. Complete = the shape lists every component the container really has, which
	// takes three independent facts, because the Forms shape can be silently partial with no unknown
	// reason at all.
	//
	// 1. No unknown reason on either axis. A value-axis reason records a build step the analyser could
	//    not enumerate; the name axis records a component attached under a name it could not read
	//    (addSubmit($dynamic)), which contributes no value and so is invisible to the value axis.
	//    Either means a component may exist that the shape does not list.
	// 2. The component is not mutated from outside its own builder. classComponentShape() walks
	//    createComponent<Name> and nothing else, so $ctrl['form']->addHidden('x') in the class that
	//    instantiates $ctrl adds a component the shape neither lists nor records as missing. This is
	//    the one input that is NOT derivable from the shape - it is asked of the Forms index.
	// 3. The analyser modelled at least one component here - a slot, a child container or a
	//    replicator. getComponentTypes() does NOT count: it is the opaque channel, the components
	//    whose value the analyser could not model, so a shape holding nothing else is evidence of a
	//    walk that understood nothing rather than of a container that holds nothing. A container
	//    attached as an already-built component ($form->addComponent($factory->create(), 'phone'))
	//    lands here with an entirely empty shape and no unknown reason.
	//    ContainerModel::replicatorInnerShape draws the same line for a replicator's inner shape.
	private function isComplete(FormShape $shape, ?string $ownerClass, string $componentName): bool
	{
		if ($shape->getUnknown()->hasUnknown() || $shape->getUnknown()->hasNameUnknown()) {
			return false;
		}

		if (($this->externallyMutated)($ownerClass, $componentName)) {
			return false;
		}

		return $shape->getSlots() !== []
			|| $shape->getContainers() !== []
			|| $shape->getReplicators() !== [];
	}

	// Every child name the shape knows, across all four channels. getComponentTypes() is
	// load-bearing rather than redundant: an omitted-value component (addSubmit, addImageButton,
	// addProtection, addReCaptcha) contributes NO slot at all, only a component type - and buttons
	// are one of the most referenced kinds in templates.
	private static function has(FormShape $shape, string $name): bool
	{
		return array_key_exists($name, $shape->getSlots())
			|| array_key_exists($name, $shape->getContainers())
			|| array_key_exists($name, $shape->getReplicators())
			|| array_key_exists($name, $shape->getComponentTypes());
	}

}
