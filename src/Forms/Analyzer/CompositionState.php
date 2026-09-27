<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use OriPhpstan\Nette\Component\Attachment\AttachmentState;
use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummary;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PHPStan\Type\MixedType;
use function array_keys;
use function in_array;

final class CompositionState
{

	/** @var array<string, ComponentSlot> */
	private array $slots;

	/** @var array<string, FormShape> */
	private array $containers;

	/** @var array<string, ReplicatorShape> */
	private array $replicators;

	/** @var array<string, string> */
	private array $containerPresence;

	/** @var array<string, string> */
	private array $replicatorPresence;

	/** @var array<string, string> */
	private array $componentTypes;

	/** @var array<string, string> */
	private array $componentTypePresence;

	private UnknownInfo $unknown;

	/**
	 * The attachment of the references the walked body mentions, carried beside the shape and joined
	 * exactly as the presences beside it are. Nothing in this class reads it and nothing derived from
	 * it reaches toFormShape(): the walk is its producer and the consumers that will retire their
	 * approximations against it are a later slice. It rides here rather than in a parallel walk so it
	 * meets at the same joins the shape does, which is the only place the two could otherwise
	 * disagree about which paths reach a program point.
	 */
	private AttachmentState $attachment;

	/**
	 * @param array<string, ComponentSlot> $slots
	 * @param array<string, FormShape> $containers
	 * @param array<string, ReplicatorShape> $replicators
	 * @param array<string, string> $containerPresence
	 * @param array<string, string> $replicatorPresence
	 * @param array<string, string> $componentTypes
	 * @param array<string, string> $componentTypePresence
	 */
	private function __construct(
		array $slots,
		array $containers,
		array $replicators,
		array $containerPresence,
		array $replicatorPresence,
		UnknownInfo $unknown,
		array $componentTypes,
		array $componentTypePresence,
		AttachmentState $attachment
	)
	{
		$this->slots = $slots;
		$this->containers = $containers;
		$this->replicators = $replicators;
		$this->containerPresence = $containerPresence;
		$this->replicatorPresence = $replicatorPresence;
		$this->componentTypes = $componentTypes;
		$this->componentTypePresence = $componentTypePresence;
		$this->unknown = $unknown;
		$this->attachment = $attachment;
	}

	public static function initial(): self
	{
		return new self([], [], [], [], [], new UnknownInfo(), [], [], AttachmentState::initial());
	}

	/** @param list<array{state: self, terminated: bool, bindings?: array<string, ControlBinding>}> $armResults */
	public static function joinBranches(self $pre, array $armResults, bool $hasImplicitFallThrough): self
	{
		$reaching = [];
		foreach ($armResults as $arm) {
			if (!$arm['terminated']) {
				$reaching[] = $arm['state'];
			}
		}

		if ($hasImplicitFallThrough) {
			$reaching[] = $pre;
		}

		$slots = self::joinSlots($pre, $armResults, $reaching, $hasImplicitFallThrough);
		$containers = self::joinContainers($pre, $armResults, $reaching, $hasImplicitFallThrough);
		$replicators = self::joinReplicators($pre, $armResults, $reaching, $hasImplicitFallThrough);
		$componentTypes = self::joinComponentTypes($pre, $armResults, $reaching, $hasImplicitFallThrough);

		$unknown = $pre->unknown;
		foreach ($armResults as $arm) {
			$unknown = $unknown->merge($arm['state']->unknown);
		}

		return new self(
			$slots,
			$containers['shapes'],
			$replicators['shapes'],
			$containers['presence'],
			$replicators['presence'],
			$unknown,
			$componentTypes['shapes'],
			$componentTypes['presence'],
			AttachmentState::join(self::attachmentsOf($reaching)),
		);
	}

	/**
	 * Presence-aware fold across the states at every `return $trackedName` point: a field is
	 * PRESENT only when present on every return path, else MAYBE. Unlike joinBranches (whose
	 * presence is key-existence relative to a pre-state) this meets each state's own per-slot
	 * presence, so a field conditionally added inside one returning arm stays MAYBE.
	 *
	 * @param list<self> $states
	 */
	public static function joinReturnPoints(array $states): self
	{
		$slots = self::foldSlots($states);
		$containers = self::foldContainers($states);
		$replicators = self::foldReplicators($states);
		$componentTypes = self::foldComponentTypes($states);

		$unknown = new UnknownInfo();
		foreach ($states as $state) {
			$unknown = $unknown->merge($state->unknown);
		}

		return new self(
			$slots,
			$containers['shapes'],
			$replicators['shapes'],
			$containers['presence'],
			$replicators['presence'],
			$unknown,
			$componentTypes['shapes'],
			$componentTypes['presence'],
			AttachmentState::join(self::attachmentsOf($states)),
		);
	}

	public function getAttachment(): AttachmentState
	{
		return $this->attachment;
	}

	public function withAttachment(AttachmentState $attachment): self
	{
		return new self(
			$this->slots,
			$this->containers,
			$this->replicators,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->unknown,
			$this->componentTypes,
			$this->componentTypePresence,
			$attachment,
		);
	}

	/**
	 * @param list<self> $states
	 * @return list<AttachmentState>
	 */
	private static function attachmentsOf(array $states): array
	{
		$attachments = [];
		foreach ($states as $state) {
			$attachments[] = $state->attachment;
		}

		return $attachments;
	}

	/**
	 * @param list<self> $states
	 * @return array<string, ComponentSlot>
	 */
	private static function foldSlots(array $states): array
	{
		$names = [];
		foreach ($states as $state) {
			foreach (array_keys($state->slots) as $name) {
				$names[$name] = true;
			}
		}

		$result = [];
		foreach (array_keys($names) as $name) {
			$merged = null;
			$presence = null;
			foreach ($states as $state) {
				$slotPresence = isset($state->slots[$name]) ? $state->slots[$name]->getPresence() : Certainty::MAYBE;
				$presence = $presence === null ? $slotPresence : Certainty::join($presence, $slotPresence);

				if (!isset($state->slots[$name])) {
					continue;
				}

				$slot = $state->slots[$name];
				$merged = $merged === null ? $slot : $merged->mergeDuplicate($slot);
			}

			if ($merged === null || $presence === null) {
				continue;
			}

			$result[$name] = $merged->withPresence(Certainty::join($presence, $merged->getPresence()));
		}

		return $result;
	}

	/**
	 * @param list<self> $states
	 * @return array{shapes: array<string, FormShape>, presence: array<string, string>}
	 */
	private static function foldContainers(array $states): array
	{
		return self::foldMap(
			$states,
			static fn (self $state): array => $state->containers,
			static fn (self $state): array => $state->containerPresence,
			static fn (FormShape $a, FormShape $b): FormShape => $a->joinBranch($b),
		);
	}

	/**
	 * @param list<self> $states
	 * @return array{shapes: array<string, ReplicatorShape>, presence: array<string, string>}
	 */
	private static function foldReplicators(array $states): array
	{
		return self::foldMap(
			$states,
			static fn (self $state): array => $state->replicators,
			static fn (self $state): array => $state->replicatorPresence,
			static fn (ReplicatorShape $a, ReplicatorShape $b): ReplicatorShape => new ReplicatorShape(
				$a->getInner()->joinBranch($b->getInner()),
				$a->getOwn()->joinBranch($b->getOwn()),
			),
		);
	}

	/**
	 * componentTypes goes through the SAME fold its shaped siblings do, over a merge that keeps the
	 * first class name recorded for a name (the union the class-name axis has always been) while the
	 * fold computes a real meet for the presence beside it. Before the axis existed this map was
	 * accumulated with a bare `??=` across every state, which is why a name one return path added
	 * came out indistinguishable from one every return path added.
	 *
	 * @param list<self> $states
	 * @return array{shapes: array<string, string>, presence: array<string, string>}
	 */
	private static function foldComponentTypes(array $states): array
	{
		return self::foldMap(
			$states,
			static fn (self $state): array => $state->componentTypes,
			static fn (self $state): array => $state->componentTypePresence,
			static fn (string $a, string $b): string => $a,
			Certainty::MAYBE,
		);
	}

	/**
	 * @template T
	 * @param list<self> $states
	 * @param callable(self): array<string, T> $mapOf
	 * @param callable(self): array<string, string> $presenceOf
	 * @param callable(T, T): T $merge
	 * @return array{shapes: array<string, T>, presence: array<string, string>}
	 */
	private static function foldMap(
		array $states,
		callable $mapOf,
		callable $presenceOf,
		callable $merge,
		string $unrecorded = Certainty::HAPPENS
	): array
	{
		$maps = [];
		$presenceMaps = [];
		foreach ($states as $state) {
			$maps[] = $mapOf($state);
			$presenceMaps[] = $presenceOf($state);
		}

		$names = [];
		foreach ($maps as $map) {
			foreach (array_keys($map) as $name) {
				$names[$name] = true;
			}
		}

		$shapes = [];
		$presence = [];
		foreach (array_keys($names) as $name) {
			$shape = null;
			$pres = null;
			foreach ($maps as $i => $map) {
				$statePresence = isset($map[$name])
					? ($presenceMaps[$i][$name] ?? $unrecorded)
					: Certainty::MAYBE;
				$pres = $pres === null ? $statePresence : Certainty::join($pres, $statePresence);

				if (!isset($map[$name])) {
					continue;
				}

				$shape = $shape === null ? $map[$name] : $merge($shape, $map[$name]);
			}

			if ($shape === null || $pres === null) {
				continue;
			}

			$shapes[$name] = $shape;
			$presence[$name] = $pres;
		}

		return ['shapes' => $shapes, 'presence' => $presence];
	}

	public function isInitial(): bool
	{
		return $this->slots === []
			&& $this->containers === []
			&& $this->replicators === []
			&& $this->componentTypes === []
			&& !$this->unknown->hasUnknown();
	}

	public function withUnknownReason(string $reason): self
	{
		return new self(
			$this->slots,
			$this->containers,
			$this->replicators,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->unknown->withReason($reason),
			$this->componentTypes,
			$this->componentTypePresence,
			$this->attachment,
		);
	}

	public function withContainerShape(string $name, FormShape $shape): self
	{
		$containers = $this->containers;
		$containers[$name] = $shape;

		$containerPresence = $this->containerPresence;
		if (!isset($containerPresence[$name])) {
			$containerPresence[$name] = Certainty::HAPPENS;
		}

		return new self(
			$this->slots,
			$containers,
			$this->replicators,
			$containerPresence,
			$this->replicatorPresence,
			$this->unknown,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->attachment,
		);
	}

	/**
	 * Folds a followed callee's contribution to the tracked form into this state, at the walk
	 * position of the call. The contribution is a shape of what the callee adds to ITS parameter,
	 * so it is a property of the callee alone — two forms routed through one helper each absorb
	 * the same contribution into their own separate state, and neither can see the other's fields.
	 * The callee's own unknown reasons come across too: a helper this walk could follow but which
	 * is itself incomplete leaves the caller incomplete.
	 */
	public function absorbCalleeContribution(FormShape $contribution): self
	{
		$slots = $this->slots;
		foreach ($contribution->getSlots() as $name => $slot) {
			$slots[$name] = isset($slots[$name]) ? $slots[$name]->mergeDuplicate($slot) : $slot;
		}

		$containers = $this->containers;
		$containerPresence = $this->containerPresence;
		foreach ($contribution->getContainers() as $name => $child) {
			$containers[$name] = isset($containers[$name]) ? $containers[$name]->merge($child) : $child;
			$containerPresence[$name] = $contribution->getContainerPresence()[$name] ?? Certainty::HAPPENS;
		}

		$replicators = $this->replicators;
		$replicatorPresence = $this->replicatorPresence;
		foreach ($contribution->getReplicators() as $name => $replicator) {
			$replicators[$name] = isset($replicators[$name])
				? new ReplicatorShape(
					$replicators[$name]->getInner()->merge($replicator->getInner()),
					$replicators[$name]->getOwn()->merge($replicator->getOwn()),
				)
				: $replicator;
			$replicatorPresence[$name] = $contribution->getReplicatorPresence()[$name] ?? Certainty::HAPPENS;
		}

		// Presence follows the class name exactly: a name this state already holds keeps its OWN
		// certainty (the callee adding it again cannot make a conditional add here unconditional, and
		// cannot make an unconditional one less certain), and a name only the callee contributes takes
		// the callee's. Absorbing at a call site inside an `if` needs no special case - the arm's own
		// state records it and joinBranches() meets it back down.
		$componentTypes = $this->componentTypes;
		$componentTypePresence = $this->componentTypePresence;
		foreach ($contribution->getComponentTypes() as $name => $fqcn) {
			$componentTypes[$name] ??= $fqcn;
			$componentTypePresence[$name] ??= $contribution->componentTypePresence($name);
		}

		return new self(
			$slots,
			$containers,
			$replicators,
			$containerPresence,
			$replicatorPresence,
			$this->unknown->merge($contribution->getUnknown()),
			$componentTypes,
			$componentTypePresence,
			$this->attachment,
		);
	}

	/**
	 * Opens a container slot's inner shape (a trailing `...<IComponent>` member), for when the
	 * container's accumulated children can no longer be proven complete — e.g. a later
	 * post-branch mutation of the container. A no-op when the name is not a container here.
	 */
	public function openContainerShape(string $name): self
	{
		if (!isset($this->containers[$name])) {
			return $this;
		}

		$containers = $this->containers;
		$containers[$name] = $containers[$name]->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);

		return new self(
			$this->slots,
			$containers,
			$this->replicators,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->unknown,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->attachment,
		);
	}

	public function withReplicatorShape(string $name, ReplicatorShape $shape): self
	{
		$replicators = $this->replicators;
		$replicators[$name] = $shape;

		$replicatorPresence = $this->replicatorPresence;
		if (!isset($replicatorPresence[$name])) {
			$replicatorPresence[$name] = Certainty::HAPPENS;
		}

		return new self(
			$this->slots,
			$this->containers,
			$replicators,
			$this->containerPresence,
			$replicatorPresence,
			$this->unknown,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->attachment,
		);
	}

	/**
	 * Every control added so far omitted, recursively — a whole-container disabler
	 * (@form-disabler) reached in the build. Applied in walk order, so controls added
	 * after the disabler call keep their values.
	 */
	public function withAllSlotsOmitted(): self
	{
		$slots = [];
		foreach ($this->slots as $name => $slot) {
			$slots[$name] = $slot->withOmitted();
		}

		$containers = [];
		foreach ($this->containers as $name => $shape) {
			$containers[$name] = $shape->withAllSlotsOmitted();
		}

		$replicators = [];
		foreach ($this->replicators as $name => $replicator) {
			$replicators[$name] = new ReplicatorShape(
				$replicator->getInner()->withAllSlotsOmitted(),
				$replicator->getOwn()->withAllSlotsOmitted(),
			);
		}

		return new self(
			$slots,
			$containers,
			$replicators,
			$this->containerPresence,
			$this->replicatorPresence,
			$this->unknown,
			$this->componentTypes,
			$this->componentTypePresence,
			$this->attachment,
		);
	}

	public function markSlotUnresolvedOrigin(string $name): self
	{
		$slots = $this->slots;
		$slots[$name] = new ComponentSlot(
			$name,
			new MixedType(),
			Certainty::HAPPENS,
			[],
			null,
			false,
			null,
			false,
			true,
		);

		$containers = $this->containers;
		$replicators = $this->replicators;
		$containerPresence = $this->containerPresence;
		$replicatorPresence = $this->replicatorPresence;
		$componentTypes = $this->componentTypes;
		$componentTypePresence = $this->componentTypePresence;
		unset($containers[$name], $replicators[$name], $containerPresence[$name], $replicatorPresence[$name], $componentTypes[$name], $componentTypePresence[$name]);

		return new self(
			$slots,
			$containers,
			$replicators,
			$containerPresence,
			$replicatorPresence,
			$this->unknown->withReason(UnknownReason::UNRESOLVED_ORIGIN),
			$componentTypes,
			$componentTypePresence,
			$this->attachment,
		);
	}

	/**
	 * @param list<array{state: self, terminated: bool, bindings?: array<string, ControlBinding>}> $armResults
	 * @param list<self> $reaching
	 * @return array<string, ComponentSlot>
	 */
	private static function joinSlots(
		self $pre,
		array $armResults,
		array $reaching,
		bool $hasImplicitFallThrough
	): array
	{
		$names = array_keys($pre->slots);
		foreach ($armResults as $arm) {
			foreach (array_keys($arm['state']->slots) as $name) {
				$names[] = $name;
			}
		}

		$result = [];
		foreach ($names as $name) {
			if (isset($result[$name])) {
				continue;
			}

			$merged = null;
			foreach ($armResults as $arm) {
				if (isset($arm['state']->slots[$name])) {
					$slot = $arm['state']->slots[$name];
					$merged = $merged === null ? $slot : $merged->mergeDuplicate($slot);
				}
			}

			if (isset($pre->slots[$name])) {
				$merged = $merged === null ? $pre->slots[$name] : $merged->mergeDuplicate($pre->slots[$name]);
			}

			if ($merged === null) {
				continue;
			}

			$presence = self::resolvePresence($name, $pre->slots, self::slotMaps($reaching), $hasImplicitFallThrough);
			$result[$name] = $merged->withPresence(Certainty::join($presence, $merged->getPresence()));
		}

		return $result;
	}

	/**
	 * @param list<array{state: self, terminated: bool, bindings?: array<string, ControlBinding>}> $armResults
	 * @param list<self> $reaching
	 * @return array{shapes: array<string, FormShape>, presence: array<string, string>}
	 */
	private static function joinContainers(
		self $pre,
		array $armResults,
		array $reaching,
		bool $hasImplicitFallThrough
	): array
	{
		return self::joinMap(
			$pre,
			$armResults,
			$reaching,
			$hasImplicitFallThrough,
			static fn (self $state): array => $state->containers,
			static fn (self $state): array => $state->containerPresence,
			static fn (FormShape $a, FormShape $b): FormShape => $a->joinBranch($b),
		);
	}

	/**
	 * @param list<array{state: self, terminated: bool, bindings?: array<string, ControlBinding>}> $armResults
	 * @param list<self> $reaching
	 * @return array{shapes: array<string, ReplicatorShape>, presence: array<string, string>}
	 */
	private static function joinReplicators(
		self $pre,
		array $armResults,
		array $reaching,
		bool $hasImplicitFallThrough
	): array
	{
		return self::joinMap(
			$pre,
			$armResults,
			$reaching,
			$hasImplicitFallThrough,
			static fn (self $state): array => $state->replicators,
			static fn (self $state): array => $state->replicatorPresence,
			static fn (ReplicatorShape $a, ReplicatorShape $b): ReplicatorShape => new ReplicatorShape(
				$a->getInner()->joinBranch($b->getInner()),
				$a->getOwn()->joinBranch($b->getOwn()),
			),
		);
	}

	/**
	 * The componentTypes twin of joinContainers()/joinReplicators(), and the one that closes the
	 * unsoundness 7c2fced9c fixed for the shape-valued maps: this map has no shape to hang a presence
	 * meet on, so it kept a plain `??=` accumulation over pre plus every arm, and a name added in ONE
	 * arm reached the join looking exactly like a name added before it. Threading a presence map
	 * beside it makes the same joinMap() apply, so resolvePresence()'s key-existence component and the
	 * arms' own carried certainty are met here exactly as they are for a container.
	 *
	 * @param list<array{state: self, terminated: bool, bindings?: array<string, ControlBinding>}> $armResults
	 * @param list<self> $reaching
	 * @return array{shapes: array<string, string>, presence: array<string, string>}
	 */
	private static function joinComponentTypes(
		self $pre,
		array $armResults,
		array $reaching,
		bool $hasImplicitFallThrough
	): array
	{
		return self::joinMap(
			$pre,
			$armResults,
			$reaching,
			$hasImplicitFallThrough,
			static fn (self $state): array => $state->componentTypes,
			static fn (self $state): array => $state->componentTypePresence,
			static fn (string $a, string $b): string => $a,
			Certainty::MAYBE,
		);
	}

	/**
	 * @template T
	 * @param list<array{state: self, terminated: bool, bindings?: array<string, ControlBinding>}> $armResults
	 * @param list<self> $reaching
	 * @param callable(self): array<string, T> $mapOf
	 * @param callable(self): array<string, string> $presenceOf
	 * @param callable(T, T): T $merge
	 * @return array{shapes: array<string, T>, presence: array<string, string>}
	 */
	private static function joinMap(
		self $pre,
		array $armResults,
		array $reaching,
		bool $hasImplicitFallThrough,
		callable $mapOf,
		callable $presenceOf,
		callable $merge,
		string $unrecorded = Certainty::HAPPENS
	): array
	{
		$preMap = $mapOf($pre);
		$prePresence = $presenceOf($pre);

		$armMaps = [];
		$armPresences = [];
		foreach ($armResults as $arm) {
			$armMaps[] = $mapOf($arm['state']);
			$armPresences[] = $presenceOf($arm['state']);
		}

		$names = array_keys($preMap);
		foreach ($armMaps as $armMap) {
			foreach (array_keys($armMap) as $name) {
				$names[] = $name;
			}
		}

		$reachingMaps = [];
		foreach ($reaching as $state) {
			$reachingMaps[] = $mapOf($state);
		}

		$shapes = [];
		$presence = [];
		foreach ($names as $name) {
			if (isset($shapes[$name])) {
				continue;
			}

			$shape = $preMap[$name] ?? null;
			$carried = isset($preMap[$name]) ? ($prePresence[$name] ?? $unrecorded) : null;
			foreach ($armMaps as $i => $armMap) {
				if (!isset($armMap[$name])) {
					continue;
				}

				$shape = $shape === null ? $armMap[$name] : $merge($shape, $armMap[$name]);
				$armCarried = $armPresences[$i][$name] ?? $unrecorded;
				$carried = $carried === null ? $armCarried : Certainty::join($carried, $armCarried);
			}

			if ($shape === null || $carried === null) {
				continue;
			}

			$shapes[$name] = $shape;
			$presence[$name] = Certainty::join(
				self::resolvePresence($name, $preMap, $reachingMaps, $hasImplicitFallThrough),
				$carried,
			);
		}

		return ['shapes' => $shapes, 'presence' => $presence];
	}

	/**
	 * Key existence ONLY, relative to the pre-state and the reaching arms - it answers whether every
	 * path that reaches this join had the name at all, which is the part of presence a branch join
	 * adds. It deliberately does NOT look at the presence a state already recorded, so its answer is
	 * a component of presence and never the whole of it: joinSlots meets it with the ComponentSlot's
	 * own presence and joinMap meets it with the carried container/replicator presence, because
	 * otherwise a name that was already MAYBE (or that an arm contributed as MAYBE) is re-promoted
	 * to HAPPENS by the mere existence of its key on every path - which is what made a
	 * conditionally-added container answer Yes to isset() after any later branch join.
	 *
	 * @param array<string, mixed> $preMap
	 * @param list<array<string, mixed>> $reachingMaps
	 */
	private static function resolvePresence(
		string $name,
		array $preMap,
		array $reachingMaps,
		bool $hasImplicitFallThrough
	): string
	{
		if (isset($preMap[$name])) {
			foreach ($reachingMaps as $map) {
				if (!isset($map[$name])) {
					return Certainty::MAYBE;
				}
			}

			return Certainty::HAPPENS;
		}

		if ($reachingMaps === [] || $hasImplicitFallThrough) {
			return Certainty::MAYBE;
		}

		foreach ($reachingMaps as $map) {
			if (!isset($map[$name])) {
				return Certainty::MAYBE;
			}
		}

		return Certainty::HAPPENS;
	}

	/**
	 * @param list<self> $states
	 * @return list<array<string, ComponentSlot>>
	 */
	private static function slotMaps(array $states): array
	{
		$maps = [];
		foreach ($states as $state) {
			$maps[] = $state->slots;
		}

		return $maps;
	}

	public function asLoopBody(self $pre): self
	{
		return self::joinBranches($pre, [['state' => $this, 'terminated' => false]], true);
	}

	public function slotPresence(string $name): ?string
	{
		return isset($this->slots[$name]) ? $this->slots[$name]->getPresence() : null;
	}

	/**
	 * The class of the child this state holds at $name, for a name it holds as a single resolved
	 * class and as neither a container nor a replicator. Null everywhere else — a name this state
	 * does not know, one it knows only as a container or replicator, one whose slot resolved no
	 * class, and one whose slot carries SEVERAL classes (two arms registering different controls
	 * under the same name), since a caller asking what the child is cannot be answered with a
	 * choice. The slot is asked first and alone: componentTypes is the sole record only for a child
	 * that has no slot at all (a submit button), and for a child that has one it is the second,
	 * coarser half of the same registration.
	 */
	public function resolvedChildClass(string $name): ?string
	{
		if (isset($this->containers[$name]) || isset($this->replicators[$name])) {
			return null;
		}

		if (isset($this->slots[$name])) {
			return $this->slots[$name]->getControlClass();
		}

		return $this->componentTypes[$name] ?? null;
	}

	/**
	 * Its componentTypes twin, and null (rather than the read-side MAYBE default) for a name this
	 * state has no entry for - the caller is asking what certainty to CARRY, so it must be able to
	 * tell a recorded MAYBE apart from nothing recorded at all.
	 */
	public function componentTypePresence(string $name): ?string
	{
		return $this->componentTypePresence[$name] ?? null;
	}

	/**
	 * A node's contribution, then whatever further registrations the same node makes — a repeated
	 * `@form-adds` being the only source of any. They fold in source order, exactly as if the method
	 * had been called once per component, so a later occurrence overwriting an earlier one's name
	 * resolves the way two literal adds under one name already do.
	 */
	public function applySequential(
		NodeContributionSummary $summary,
		bool $replaceSlot = false,
		string $presence = Certainty::HAPPENS
	): self
	{
		$state = $this->applyOneSequential($summary, $replaceSlot, $presence);
		foreach ($summary->getAdditional() as $additional) {
			$state = $state->applyOneSequential($additional, $replaceSlot, $presence);
		}

		return $state;
	}

	private function applyOneSequential(
		NodeContributionSummary $summary,
		bool $replaceSlot,
		string $presence
	): self
	{
		$slots = $this->slots;
		$containers = $this->containers;
		$replicators = $this->replicators;
		$containerPresence = $this->containerPresence;
		$replicatorPresence = $this->replicatorPresence;
		$componentTypes = $this->componentTypes;
		$componentTypePresence = $this->componentTypePresence;
		$unknown = $this->unknown;

		$resolution = $summary->getResolution();

		if ($resolution !== null) {
			foreach ($resolution->getUnknownReasons() as $reason) {
				$unknown = $unknown->withReason($reason);
			}
		}

		// dynamic_name only matters to the VALUE axis when the resolution would contribute something
		// keyed by name (value/container/replicator). For KIND_OMITTED nothing is contributed and for
		// KIND_UNKNOWN_TYPE the resolution's own reason already covers it, so an unresolved name is
		// value noise there. Dropping this guard reintroduces spurious dynamic_name on
		// addSubmit($dynamic) and extension-method adds (U02).
		//
		// The NAME axis takes it in every case instead of losing it: addSubmit($dynamic) DOES attach
		// a component, under a name this walk cannot read, so the shape's name set is incomplete
		// even where no value is missing. Without this the component is dropped at the
		// $name === null return below leaving no trace at all.
		$contributesNamed = $resolution !== null && in_array(
			$resolution->getKind(),
			[ControlValueResolution::KIND_VALUE, ControlValueResolution::KIND_CONTAINER, ControlValueResolution::KIND_REPLICATOR],
			true,
		);

		foreach ($summary->getNodeUnknownReasons() as $reason) {
			if ($reason === UnknownReason::DYNAMIC_NAME) {
				$unknown = $unknown->withNameReason($reason);

				if (!$contributesNamed) {
					continue;
				}
			}

			$unknown = $unknown->withReason($reason);
		}

		if ($summary->getOp() === NodeContributionSummary::OP_REMOVE) {
			$name = $summary->getName();
			if ($name !== null) {
				unset($slots[$name], $containers[$name], $replicators[$name], $containerPresence[$name], $replicatorPresence[$name], $componentTypes[$name], $componentTypePresence[$name]);
			}

			return new self(
				$slots,
				$containers,
				$replicators,
				$containerPresence,
				$replicatorPresence,
				$unknown,
				$componentTypes,
				$componentTypePresence,
				$this->attachment,
			);
		}

		$name = $summary->getName();
		if ($name === null || $resolution === null) {
			return new self(
				$slots,
				$containers,
				$replicators,
				$containerPresence,
				$replicatorPresence,
				$unknown,
				$componentTypes,
				$componentTypePresence,
				$this->attachment,
			);
		}

		switch ($resolution->getKind()) {
			case ControlValueResolution::KIND_VALUE:
				$new = new ComponentSlot(
					$name,
					$resolution->getValueType() ?? new MixedType(),
					$presence,
					[$summary->getNodeId()],
					$resolution->getControlClass(),
					$resolution->isNullable(),
					$resolution->getAcceptedSetSpec(),
					$resolution->isRequired(),
					false,
					$resolution->getOmission(),
				);
				$slots[$name] = !$replaceSlot && isset($slots[$name]) ? $slots[$name]->mergeDuplicate($new) : $new;

				break;
			case ControlValueResolution::KIND_UNKNOWN_TYPE:
				$unknownClass = $resolution->getControlClass();
				if ($unknownClass !== null) {
					$componentTypes[$name] = $unknownClass;
					$componentTypePresence[$name] = $presence;
				}

				$new = new ComponentSlot(
					$name,
					$resolution->getValueType() ?? new MixedType(),
					$presence,
					[$summary->getNodeId()],
					null,
					$resolution->isNullable(),
					$resolution->getAcceptedSetSpec(),
					false,
					true,
					$resolution->getOmission(),
				);
				$slots[$name] = !$replaceSlot && isset($slots[$name]) ? $slots[$name]->mergeDuplicate($new) : $new;

				break;
			case ControlValueResolution::KIND_CONTAINER:
				$containers[$name] = self::openContainerPlaceholder();
				$containerPresence[$name] = $presence;

				break;
			case ControlValueResolution::KIND_REPLICATOR:
				$replicators[$name] = self::openReplicatorPlaceholder();
				$replicatorPresence[$name] = $presence;
				$replicatorClass = $resolution->getControlClass();
				if ($replicatorClass !== null) {
					$componentTypes[$name] = $replicatorClass;
					$componentTypePresence[$name] = $presence;
				}

				break;

			// The ONE arm for which componentTypes is the SOLE record of the child - addSubmit(),
			// addImageButton(), addProtection() and every custom add* returning a button - so this
			// write is the only presence evidence the name will ever have, and $presence (MAYBE inside
			// a ternary or a short-circuit, HAPPENS otherwise, met again by joinBranches() for an
			// if/loop arm) is the same certainty the shaped arms above record.
			case ControlValueResolution::KIND_OMITTED:
				$omittedClass = $resolution->getControlClass();
				if ($omittedClass !== null) {
					$componentTypes[$name] = $omittedClass;
					$componentTypePresence[$name] = $presence;
				}

				break;
		}

		return new self(
			$slots,
			$containers,
			$replicators,
			$containerPresence,
			$replicatorPresence,
			$unknown,
			$componentTypes,
			$componentTypePresence,
			$this->attachment,
		);
	}

	/**
	 * The placeholder the KIND_CONTAINER arm above records before FormShapeAnalyzer's own
	 * composeContainer() replaces it with the real child shape. OPEN, never closed-and-empty, for
	 * the same reason openReplicatorPlaceholder() is — except that here the reason is not
	 * hypothetical. Unlike its replicator sibling, this value is measurably REACHABLE: an
	 * unconditional probe marking every value this arm produces and following it transitively
	 * through every FormShape rebuild (all seven derivations, both join/fold merge callbacks by way
	 * of joinBranch(), and TypeCanonicalizer::canonicalizeShape()) saw it created 664 times across
	 * the Forms fixture suite, and reach toFormShape() 5 times and a consumer read 20 times —
	 * still closed and still empty at every one of them. The reads include
	 * FormShapeUnknownAccessRule::classifyLeaf() and ComponentPath::hasDefiniteChild(), i.e. the
	 * presence authority itself.
	 *
	 * What reaches it is a container added inside an `if`/loop HEADER expression
	 * (`foreach ($form->addContainer('rows')->getComponents() as $r)`): applyStatementHeaders()
	 * registers the container, then the `If_`/`Switch_`/`TryCatch` arm `continue`s and
	 * attachChildShapes() — which is what would call composeContainer() — never runs. Closed and
	 * empty there is a positive claim that the container has no children, so any child genuinely
	 * added in that same header reads as ABSENT: the C1 false-positive class. Open says the honest
	 * thing instead — a container is here, its children are not known.
	 */
	private static function openContainerPlaceholder(): FormShape
	{
		return FormShape::empty()->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);
	}

	/**
	 * The placeholder the KIND_REPLICATOR arm above records before FormShapeAnalyzer's own
	 * composeReplicator() replaces it with the real inner/own shapes, one statement later in
	 * walkStmts(). Both halves are OPEN, never closed-and-empty, for the reason
	 * composeReplicatorOwnShape() already spells for the escaped-holder case: a closed empty shape is
	 * a positive claim that the replicator has no children, so anything that ever read this
	 * placeholder would classify a genuinely-added row field or own child as ABSENT — the C1
	 * false-positive class. Open says the honest thing instead: a replicator is here, its children are
	 * not known yet.
	 *
	 * Measured rather than assumed: an unconditional probe marking every value this arm produces and
	 * following it transitively through every ReplicatorShape rebuild (both join/fold callbacks,
	 * withMergedContribution(), withAllSlotsOmitted(), FormShape's own three, and
	 * TypeCanonicalizer::canonicalizeShape()) saw it created 433 times across the Forms fixture suite
	 * and 107 times across a cold whole-corpus run, and reach toFormShape() or any
	 * FormShape::getReplicators() read ZERO times in either — composeReplicator() supersedes it on
	 * every path the corpus and the fixtures reach. The same probe pointed at composeReplicator()'s
	 * own shape instead logged 421 survivals and 2936 reads, so the instrument was live. Open is
	 * therefore not a behaviour change; it is the removal of a value that would be wrong if a path
	 * that skips composeReplicator() — a replicator add in an `if`/loop HEADER, or one in a `return`
	 * statement, neither of which reaches attachChildShapes() — ever appeared in real code.
	 */
	private static function openReplicatorPlaceholder(): ReplicatorShape
	{
		$open = FormShape::empty()->withUnknownReason(UnknownReason::CONTAINER_REFERENCE);

		return new ReplicatorShape($open, $open);
	}

	/**
	 * The attachment is deliberately absent from the shape this produces. A FormShape is a fact about
	 * a container's CHILDREN, is persisted and is read across files; an attachment is a fact about the
	 * REFERENCES one body holds, and it stops being meaningful the moment the walk that named them
	 * ends. Carrying it out of here would give the cache a per-body value keyed as if it were
	 * per-container.
	 */
	public function toFormShape(?string $className): FormShape
	{
		return new FormShape(
			$className,
			$this->slots,
			$this->containers,
			$this->replicators,
			$this->unknown,
			[],
			$this->containerPresence,
			$this->replicatorPresence,
			$this->componentTypes,
			$this->componentTypePresence,
		);
	}

}
