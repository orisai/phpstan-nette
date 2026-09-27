<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;
use function array_keys;
use function array_unique;
use function array_values;
use function assert;
use function implode;
use function ltrim;

final class FormShape
{

	private ?string $className;

	/** @var array<string, ComponentSlot> */
	private array $slots;

	/** @var array<string, FormShape> */
	private array $containers;

	/** @var array<string, ReplicatorShape> */
	private array $replicators;

	private UnknownInfo $unknown;

	/** @var list<string> */
	private array $originNodeIds;

	/** @var array<string, string> */
	private array $containerPresence;

	/** @var array<string, string> */
	private array $replicatorPresence;

	/** @var array<string, string> */
	private array $componentTypes;

	/** @var array<string, string> */
	private array $componentTypePresence;

	private ?string $mappedType;

	/**
	 * @param array<string, ComponentSlot> $slots
	 * @param array<string, FormShape> $containers
	 * @param array<string, ReplicatorShape> $replicators
	 * @param list<string> $originNodeIds
	 * @param array<string, string> $containerPresence
	 * @param array<string, string> $replicatorPresence
	 * @param array<string, string> $componentTypes
	 * @param array<string, string> $componentTypePresence
	 */
	public function __construct(
		?string $className,
		array $slots,
		array $containers,
		array $replicators,
		UnknownInfo $unknown,
		array $originNodeIds,
		array $containerPresence = [],
		array $replicatorPresence = [],
		array $componentTypes = [],
		array $componentTypePresence = [],
		?string $mappedType = null
	)
	{
		// Producers spell the same class both ways (FormShape::empty('\\' . $fqcn) beside
		// new FormShape($fqcn, ...)), and the spelling reaches a carrier verbatim: getClassName()
		// feeds `new FormShapeType(...)` / `new ObjectType(...)` unltrimmed at several sites, and
		// mergeClassName picks the OTHER side whenever the two denote the same class, so which
		// spelling survived depended on which producer merged last. Normalised here, the way
		// ComponentSlot already normalises its control class, so there is one spelling to compare.
		$this->className = $className === null ? null : ltrim($className, '\\');
		$this->slots = $slots;
		$this->containers = $containers;
		$this->replicators = $replicators;
		$this->unknown = $unknown;
		$this->originNodeIds = $originNodeIds;
		$this->containerPresence = $containerPresence;
		$this->replicatorPresence = $replicatorPresence;
		$this->componentTypes = $componentTypes;
		$this->componentTypePresence = $componentTypePresence;
		$this->mappedType = $mappedType;
	}

	public static function empty(?string $className = null): self
	{
		return new self($className, [], [], [], new UnknownInfo(), [], [], [], [], []);
	}

	public function getClassName(): ?string
	{
		return $this->className;
	}

	/** @return array<string, ComponentSlot> */
	public function getSlots(): array
	{
		return $this->slots;
	}

	/** @return array<string, FormShape> */
	public function getContainers(): array
	{
		return $this->containers;
	}

	/** @return array<string, ReplicatorShape> */
	public function getReplicators(): array
	{
		return $this->replicators;
	}

	public function getUnknown(): UnknownInfo
	{
		return $this->unknown;
	}

	/** @return list<string> */
	public function getOriginNodeIds(): array
	{
		return $this->originNodeIds;
	}

	/** @return array<string, string> */
	public function getContainerPresence(): array
	{
		return $this->containerPresence;
	}

	/** @return array<string, string> */
	public function getReplicatorPresence(): array
	{
		return $this->replicatorPresence;
	}

	/** @return array<string, string> */
	public function getComponentTypes(): array
	{
		return $this->componentTypes;
	}

	/**
	 * The presence axis over componentTypes, keyed exactly like it: the certainty that the recorded
	 * component was attached on EVERY path the walk joined, as opposed to on at least one of them.
	 * Kept as a parallel map rather than folded into the entry so the class-name reads (18 of them,
	 * several in hot paths) keep costing one array lookup.
	 *
	 * Read it through componentTypePresence() below, never directly, unless you are deriving a new
	 * shape - the default for a missing key is the whole point and must not be re-spelled per site.
	 *
	 * @return array<string, string>
	 */
	public function getComponentTypePresence(): array
	{
		return $this->componentTypePresence;
	}

	/**
	 * componentTypes plays two roles, and this axis answers for exactly one of them.
	 *
	 * For a value-less control - a KIND_OMITTED addSubmit(), i.e. every submit button - componentTypes
	 * is the SOLE record of the child, so this axis is the only presence answer that exists for it.
	 * For a KIND_UNKNOWN_TYPE slot, a KIND_REPLICATOR or a ContainerModel factory child, the entry is
	 * a class-name COMPANION to a name that the slots/containers/replicators channel already owns and
	 * already answers presence for. One map cannot mean both things at the READ site, so the read
	 * order settles it: ComponentPath::hasDefiniteChild() asks the three shaped channels first and
	 * only reaches this when none of them holds the name. The two answers therefore never compete -
	 * they are written from the same certainty in the same applySequential() arm, and where they
	 * could still drift the shaped channel wins by construction.
	 *
	 * A missing key is MAYBE, not HAPPENS, and that asymmetry against getContainerPresence() /
	 * getReplicatorPresence() is deliberate. Since 744bf824e the presence axis has no |null
	 * redundancy behind it: a wrong Yes makes core's issetCheck() narrow an absent offset to
	 * non-nullable and makes a real absence go unreported, with nothing left to catch it, while a
	 * wrong Maybe is merely imprecise. So a shape that reaches a reader without this axis populated -
	 * a hand-built test double, a producer a later refactor forgets - degrades to exactly the
	 * behaviour that predates the axis instead of asserting something it never measured.
	 */
	public function componentTypePresence(string $name): string
	{
		return $this->componentTypePresence[$name] ?? Certainty::MAYBE;
	}

	/**
	 * The custom value class this container maps into via $container->setMappedType(),
	 * or null for the default ArrayHash/array crate. For a replicator's inner shape
	 * this is the per-item DTO each replica is mapped to.
	 */
	public function getMappedType(): ?string
	{
		return $this->mappedType;
	}

	public function withMappedType(?string $mappedType): self
	{
		return new self(
			$this->className,
			$this->slots,
			$this->containers,
			$this->replicators,
			$this->unknown,
			$this->originNodeIds,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->componentTypes,
			$this->componentTypePresence,
			$mappedType,
		);
	}

	/**
	 * Every control omitted, recursively — the shape after a whole-container disabler
	 * (a @form-disabler method, e.g. one looping setDisabled() over getComponents(true)):
	 * the components stay reachable but all drop out of getValues().
	 */
	public function withAllSlotsOmitted(): self
	{
		$slots = [];
		foreach ($this->slots as $name => $slot) {
			$slots[$name] = $slot->withOmitted();
		}

		$containers = [];
		foreach ($this->containers as $name => $child) {
			$containers[$name] = $child->withAllSlotsOmitted();
		}

		$replicators = [];
		foreach ($this->replicators as $name => $replicator) {
			$replicators[$name] = new ReplicatorShape(
				$replicator->getInner()->withAllSlotsOmitted(),
				$replicator->getOwn()->withAllSlotsOmitted(),
			);
		}

		return new self(
			$this->className,
			$slots,
			$containers,
			$replicators,
			$this->unknown,
			$this->originNodeIds,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->mappedType,
		);
	}

	public function withClassName(?string $className): self
	{
		return new self(
			$className,
			$this->slots,
			$this->containers,
			$this->replicators,
			$this->unknown,
			$this->originNodeIds,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->mappedType,
		);
	}

	public function withContainer(string $name, self $container): self
	{
		$containers = $this->containers;
		$containers[$name] = $container;

		return new self(
			$this->className,
			$this->slots,
			$containers,
			$this->replicators,
			$this->unknown,
			$this->originNodeIds,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->mappedType,
		);
	}

	public function withoutUnknownReason(string $reason): self
	{
		return new self(
			$this->className,
			$this->slots,
			$this->containers,
			$this->replicators,
			$this->unknown->withoutReason($reason),
			$this->originNodeIds,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->mappedType,
		);
	}

	public function withUnknownReason(string $reason): self
	{
		return new self(
			$this->className,
			$this->slots,
			$this->containers,
			$this->replicators,
			$this->unknown->withReason($reason),
			$this->originNodeIds,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->mappedType,
		);
	}

	public function merge(self $other): self
	{
		$slots = $this->slots;
		foreach ($other->slots as $name => $slot) {
			$slots[$name] = isset($slots[$name]) ? $slots[$name]->mergeDuplicate($slot) : $slot;
		}

		$containers = $this->containers;
		foreach ($other->containers as $name => $child) {
			$containers[$name] = isset($containers[$name]) ? $containers[$name]->merge($child) : $child;
		}

		$replicators = $this->replicators;
		foreach ($other->replicators as $name => $rep) {
			$replicators[$name] = isset($replicators[$name])
				? new ReplicatorShape(
					$replicators[$name]->getInner()->merge($rep->getInner()),
					$replicators[$name]->getOwn()->merge($rep->getOwn()),
				)
				: $rep;
		}

		$containerPresence = self::mergePresence(
			array_keys($containers),
			$this->containerPresence,
			$other->containerPresence,
			$this->containers,
			$other->containers,
		);
		$replicatorPresence = self::mergePresence(
			array_keys($replicators),
			$this->replicatorPresence,
			$other->replicatorPresence,
			$this->replicators,
			$other->replicators,
		);

		$componentTypes = $this->componentTypes;
		foreach ($other->componentTypes as $name => $fqcn) {
			$componentTypes[$name] ??= $fqcn;
		}

		$componentTypePresence = self::mergePresence(
			array_keys($componentTypes),
			$this->componentTypePresence,
			$other->componentTypePresence,
			$this->componentTypes,
			$other->componentTypes,
			Certainty::MAYBE,
		);

		return new self(
			$this->mergeClassName($other->className),
			$slots,
			$containers,
			$replicators,
			$this->unknown->merge($other->unknown),
			array_values(array_unique([...$this->originNodeIds, ...$other->originNodeIds])),
			$containerPresence,
			$replicatorPresence,
			$componentTypes,
			$componentTypePresence,
			$this->mappedType ?? $other->mappedType,
		);
	}

	// A name present on only one side degrades to MAYBE; merge() would claim both sides definite.
	public function joinBranch(self $other): self
	{
		$slots = [];
		foreach (array_unique([...array_keys($this->slots), ...array_keys($other->slots)]) as $name) {
			$a = $this->slots[$name] ?? null;
			$b = $other->slots[$name] ?? null;
			if ($a !== null && $b !== null) {
				$slots[$name] = $a->mergeDuplicate($b);
			} else {
				$onlySide = $a ?? $b;
				assert($onlySide instanceof ComponentSlot);
				$slots[$name] = $onlySide->withPresence(Certainty::join($onlySide->getPresence(), Certainty::MAYBE));
			}
		}

		$containerNames = array_unique([...array_keys($this->containers), ...array_keys($other->containers)]);
		$containers = [];
		foreach ($containerNames as $name) {
			$a = $this->containers[$name] ?? null;
			$b = $other->containers[$name] ?? null;
			if ($a !== null && $b !== null) {
				$containers[$name] = $a->joinBranch($b);
			} else {
				$onlySide = $a ?? $b;
				assert($onlySide instanceof self);
				$containers[$name] = $onlySide;
			}
		}

		$replicatorNames = array_unique([...array_keys($this->replicators), ...array_keys($other->replicators)]);
		$replicators = [];
		foreach ($replicatorNames as $name) {
			$a = $this->replicators[$name] ?? null;
			$b = $other->replicators[$name] ?? null;
			if ($a !== null && $b !== null) {
				$replicators[$name] = new ReplicatorShape(
					$a->getInner()->joinBranch($b->getInner()),
					$a->getOwn()->joinBranch($b->getOwn()),
				);
			} else {
				$onlySide = $a ?? $b;
				assert($onlySide instanceof ReplicatorShape);
				$replicators[$name] = $onlySide;
			}
		}

		$containerPresence = self::joinPresence(
			$containerNames,
			$this->containerPresence,
			$other->containerPresence,
			$this->containers,
			$other->containers,
		);
		$replicatorPresence = self::joinPresence(
			$replicatorNames,
			$this->replicatorPresence,
			$other->replicatorPresence,
			$this->replicators,
			$other->replicators,
		);

		// The class-name accumulation stays a union - a name either side recorded keeps its class, so
		// the TYPE axis still resolves a button an arm added. The PRESENCE axis is a meet instead, and
		// that difference is the whole point of the axis: accumulating presence the way the class name
		// accumulates is exactly the unsoundness this shape had, where a name one arm added answered as
		// confidently as one every arm added.
		$componentTypes = $this->componentTypes;
		foreach ($other->componentTypes as $name => $fqcn) {
			$componentTypes[$name] ??= $fqcn;
		}

		$componentTypePresence = self::joinPresence(
			array_keys($componentTypes),
			$this->componentTypePresence,
			$other->componentTypePresence,
			$this->componentTypes,
			$other->componentTypes,
			Certainty::MAYBE,
		);

		return new self(
			$this->mergeClassName($other->className),
			$slots,
			$containers,
			$replicators,
			$this->unknown->merge($other->unknown),
			array_values(array_unique([...$this->originNodeIds, ...$other->originNodeIds])),
			$containerPresence,
			$replicatorPresence,
			$componentTypes,
			$componentTypePresence,
			$this->mappedType ?? $other->mappedType,
		);
	}

	private function mergeClassName(?string $other): ?string
	{
		if ($this->className === null) {
			return $other;
		}

		if ($other === null) {
			return $this->className;
		}

		$thisType = new ObjectType(ltrim($this->className, '\\'));
		$otherType = new ObjectType(ltrim($other, '\\'));

		if ($thisType->isSuperTypeOf($otherType)->yes()) {
			return $other;
		}

		return $this->className;
	}

	/**
	 * $unrecorded is what a child held by one side with NO presence entry of its own means. It is
	 * HAPPENS for containers and replicators, whose every producer records presence, and MAYBE for
	 * componentTypes, whose axis is younger and carries no |null redundancy behind it - see
	 * componentTypePresence().
	 *
	 * @param list<string> $names
	 * @param array<string, string> $presenceA
	 * @param array<string, string> $presenceB
	 * @param array<string, mixed> $childrenA
	 * @param array<string, mixed> $childrenB
	 * @return array<string, string>
	 */
	private static function mergePresence(
		array $names,
		array $presenceA,
		array $presenceB,
		array $childrenA,
		array $childrenB,
		string $unrecorded = Certainty::HAPPENS
	): array
	{
		$result = $presenceA;
		foreach ($names as $name) {
			$a = $presenceA[$name] ?? (isset($childrenA[$name]) ? $unrecorded : null);
			$b = $presenceB[$name] ?? (isset($childrenB[$name]) ? $unrecorded : null);
			if ($a === null && $b === null) {
				continue;
			}

			$result[$name] = $a === null
				? $b
				: ($b === null ? $a : Certainty::join($a, $b));
		}

		return $result;
	}

	/**
	 * $unrecorded as in mergePresence().
	 *
	 * @param array<string> $names
	 * @param array<string, string> $presenceA
	 * @param array<string, string> $presenceB
	 * @param array<string, mixed> $childrenA
	 * @param array<string, mixed> $childrenB
	 * @return array<string, string>
	 */
	private static function joinPresence(
		array $names,
		array $presenceA,
		array $presenceB,
		array $childrenA,
		array $childrenB,
		string $unrecorded = Certainty::HAPPENS
	): array
	{
		$result = [];
		foreach ($names as $name) {
			$a = $presenceA[$name] ?? (isset($childrenA[$name]) ? $unrecorded : null);
			$b = $presenceB[$name] ?? (isset($childrenB[$name]) ? $unrecorded : null);
			if ($a === null && $b === null) {
				continue;
			}

			$result[$name] = $a === null
				? Certainty::join($b, Certainty::MAYBE)
				: ($b === null ? Certainty::join($a, Certainty::MAYBE) : Certainty::join($a, $b));
		}

		return $result;
	}

	public function describe(VerbosityLevel $level): string
	{
		$parts = [];

		foreach ($this->slots as $name => $slot) {
			$presence = $slot->getPresence();
			$optionalMarker = $presence === Certainty::MAYBE ? '?' : '';
			$value = $slot->isTypeOpaque() ? '*UNKNOWN*' : $slot->getValueType()->describe($level);
			if (isset($this->containers[$name])) {
				$child = $this->containers[$name];
				$value = $child->getClassName() . $child->describe($level) . '|' . $value;
			}

			$parts[] = $name . $optionalMarker . ': ' . $value;
		}

		foreach ($this->containers as $name => $child) {
			if (isset($this->slots[$name])) {
				continue;
			}

			$optionalMarker = ($this->containerPresence[$name] ?? Certainty::HAPPENS) === Certainty::MAYBE ? '?' : '';
			$parts[] = $name . $optionalMarker . ': ' . $child->getClassName() . $child->describe($level);
		}

		foreach ($this->replicators as $name => $rep) {
			$optionalMarker = ($this->replicatorPresence[$name] ?? Certainty::HAPPENS) === Certainty::MAYBE ? '?' : '';
			$repInner = $rep->getInner();
			$repOwn = $rep->getOwn();
			// Included whenever non-trivial so that two shapes differing ONLY in a replicator's own
			// children (content or open/closed state) never describe identically - FormShapeType::
			// equals() compares describe(precise()), and union dedup (FormShape::merge()/joinBranch())
			// relies on that comparison to never silently collapse two distinct own shapes into one.
			$ownSuffix = self::isTrivialOwn($repOwn)
				? ''
				: '+own' . $repOwn->getClassName() . $repOwn->describe($level);
			$parts[] = $name . $optionalMarker . ': array<int, ' . $repInner->getClassName() . $repInner->describe(
				$level,
			) . '>' . $ownSuffix;
		}

		$body = implode(', ', $parts);

		if ($this->unknown->hasUnknown()) {
			$suffix = '…+unknown(' . implode(',', $this->unknown->getReasons()) . ')';
			$body = $body === '' ? $suffix : $body . ', ' . $suffix;
		}

		return '{' . $body . '}';
	}

	private static function isTrivialOwn(self $own): bool
	{
		return $own->slots === []
			&& $own->containers === []
			&& $own->replicators === []
			&& $own->componentTypes === []
			&& !$own->unknown->hasUnknown();
	}

}
