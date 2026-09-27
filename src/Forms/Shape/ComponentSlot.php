<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function ltrim;

final class ComponentSlot
{

	private string $name;

	private Type $valueType;

	private string $presence;

	/** @var list<string> */
	private array $contributingNodeIds;

	/** @var list<string>|null */
	private ?array $controlClasses;

	private bool $nullable;

	private ?string $acceptedSetSpec;

	private bool $required;

	private bool $typeOpaque;

	private string $omission;

	/** @param list<string> $contributingNodeIds */
	public function __construct(
		string $name,
		Type $valueType,
		string $presence,
		array $contributingNodeIds,
		?string $controlClass = null,
		bool $nullable = false,
		?string $acceptedSetSpec = null,
		bool $required = false,
		bool $typeOpaque = false,
		string $omission = Certainty::NEVER
	)
	{
		$this->name = $name;
		$this->valueType = $valueType;
		$this->presence = $presence;
		$this->contributingNodeIds = $contributingNodeIds;
		$this->controlClasses = $controlClass === null ? null : [ltrim($controlClass, '\\')];
		$this->nullable = $nullable;
		$this->acceptedSetSpec = $acceptedSetSpec;
		$this->required = $required;
		$this->typeOpaque = $typeOpaque;
		$this->omission = $omission;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getValueType(): Type
	{
		return $this->valueType;
	}

	public function getPresence(): string
	{
		return $this->presence;
	}

	/** @return list<string> */
	public function getContributingNodeIds(): array
	{
		return $this->contributingNodeIds;
	}

	public function getControlClass(): ?string
	{
		return $this->controlClasses === null || count($this->controlClasses) !== 1
			? null
			: $this->controlClasses[0];
	}

	public function getControlType(): ?Type
	{
		if ($this->controlClasses === null) {
			return null;
		}

		$types = array_map(static fn (string $cls): Type => new ObjectType($cls), $this->controlClasses);

		return count($types) === 1 ? $types[0] : TypeCombinator::union(...$types);
	}

	/** @return list<string>|null */
	public function getControlClasses(): ?array
	{
		return $this->controlClasses;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function isRequired(): bool
	{
		return $this->required;
	}

	public function isTypeOpaque(): bool
	{
		return $this->typeOpaque;
	}

	/**
	 * The omission axis, separate from presence: presence answers "is the component
	 * attached" (drives a: vs a?:), omission answers "does Nette's isOmitted() drop it from
	 * getValues()". NEVER = always in the values, HAPPENS = always dropped, MAYBE = dropped
	 * on some path, UNKNOWN = decided by a value the analysis cannot read.
	 */
	public function getOmission(): string
	{
		return $this->omission;
	}

	/**
	 * A definitely-omitted control (setDisabled()/setOmitted(true) on every path): still a
	 * reachable component, but excluded from getValues(), so the values projection skips it.
	 */
	public function isOmitted(): bool
	{
		return $this->omission === Certainty::HAPPENS;
	}

	/**
	 * The value may be absent from getValues(): either the component itself is only
	 * conditionally attached (presence MAYBE), or it is present but its omission is not
	 * certain (MAYBE/UNKNOWN). Reading the missing key yields null, so the value widens to
	 * nullable rather than being dropped.
	 */
	public function valueMaybeAbsent(): bool
	{
		return $this->presence === Certainty::MAYBE
			|| $this->omission === Certainty::MAYBE
			|| $this->omission === Certainty::UNKNOWN;
	}

	public function getAcceptedSetSpec(): ?string
	{
		return $this->acceptedSetSpec;
	}

	/**
	 * The value type once the field has been submitted and passed its required
	 * validation: Nette's isFilled() rejects null, '' and [], so a required value is
	 * non-null, a required string is non-empty, and a required list is non-empty.
	 * For a non-required field this is just the plain value type.
	 */
	public function getFilledValueType(): Type
	{
		if (!$this->required) {
			return $this->valueType;
		}

		$parts = [];
		foreach (self::members(TypeCombinator::removeNull($this->valueType)) as $part) {
			if ($part->isString()->yes()) {
				$parts[] = TypeCombinator::intersect($part, new AccessoryNonEmptyStringType());
			} elseif ($part->isArray()->yes()) {
				$parts[] = TypeCombinator::intersect($part, new NonEmptyArrayType());
			} else {
				$parts[] = $part;
			}
		}

		return TypeCombinator::union(...$parts);
	}

	/** @return list<Type> */
	private static function members(Type $type): array
	{
		return $type instanceof UnionType ? $type->getTypes() : [$type];
	}

	public function withPresence(string $presence): self
	{
		if ($this->presence === $presence) {
			return $this;
		}

		$copy = clone $this;
		$copy->presence = $presence;

		return $copy;
	}

	/** @param list<string>|null $controlClasses */
	public function withControlClasses(?array $controlClasses): self
	{
		$copy = clone $this;
		$copy->controlClasses = $controlClasses;

		return $copy;
	}

	public function withOmitted(): self
	{
		if ($this->omission === Certainty::HAPPENS) {
			return $this;
		}

		$copy = clone $this;
		$copy->omission = Certainty::HAPPENS;

		return $copy;
	}

	public function mergeDuplicate(self $other): self
	{
		// Presence and omission are independent axes: a branch that omits (setDisabled) the
		// control does not make the component itself less present, so joining a NEVER-omitted
		// with an always-omitted arm yields MAYBE omission while presence stays whatever the
		// attachment join gives — no cross-pollution into presence.
		$result = new self(
			$this->name,
			TypeCombinator::union($this->valueType, $other->valueType),
			Certainty::join($this->presence, $other->presence),
			array_values(array_unique(array_merge($this->contributingNodeIds, $other->contributingNodeIds))),
			null,
			$this->nullable || $other->nullable,
			$this->acceptedSetSpec === $other->acceptedSetSpec ? $this->acceptedSetSpec : null,
			$this->required && $other->required,
			$this->typeOpaque || $other->typeOpaque,
			Certainty::join($this->omission, $other->omission),
		);

		$result->controlClasses = $this->controlClasses === null || $other->controlClasses === null
			? null
			: array_values(array_unique(array_merge($this->controlClasses, $other->controlClasses)));

		return $result;
	}

}
