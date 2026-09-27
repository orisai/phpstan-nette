<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final class ControlValueResolution
{

	public const KIND_VALUE = 'value';

	public const KIND_CONTAINER = 'container';

	public const KIND_REPLICATOR = 'replicator';

	public const KIND_OMITTED = 'omitted';

	public const KIND_UNKNOWN_TYPE = 'unknown_type';

	private string $kind;

	private ?Type $valueType;

	/** @var list<string> */
	private array $unknownReasons;

	private ?string $controlClass;

	private bool $nullable;

	private ?string $acceptedSetSpec;

	private bool $required;

	private string $omission = Certainty::NEVER;

	private ?int $replicatorFactoryArgPosition;

	/** @param list<string> $unknownReasons */
	public function __construct(
		string $kind,
		?Type $valueType,
		array $unknownReasons = [],
		?string $controlClass = null,
		bool $nullable = false,
		?string $acceptedSetSpec = null,
		bool $required = false,
		?int $replicatorFactoryArgPosition = null
	)
	{
		$this->kind = $kind;
		$this->valueType = $valueType;
		$this->unknownReasons = $unknownReasons;
		$this->controlClass = $controlClass;
		$this->nullable = $nullable;
		$this->acceptedSetSpec = $acceptedSetSpec;
		$this->required = $required;
		$this->replicatorFactoryArgPosition = $replicatorFactoryArgPosition;
	}

	public static function applyNullable(self $r): self
	{
		if ($r->getKind() === self::KIND_VALUE && $r->getValueType() !== null) {
			// A nullable string control returns null for an empty value (TextBase::getValue
			// maps '' to null), so its non-null value is always non-empty.
			$base = $r->getValueType();
			if ($base->isString()->yes()) {
				$base = TypeCombinator::intersect($base, new AccessoryNonEmptyStringType());
			}

			return new self(
				self::KIND_VALUE,
				TypeCombinator::union($base, new NullType()),
				$r->getUnknownReasons(),
				$r->getControlClass(),
				true,
				$r->getAcceptedSetSpec(),
				$r->isRequired(),
			);
		}

		return $r;
	}

	public function withRequired(): self
	{
		$copy = clone $this;
		$copy->required = true;

		return $copy;
	}

	public function withOmitted(): self
	{
		$copy = clone $this;
		$copy->omission = Certainty::HAPPENS;

		return $copy;
	}

	public function withOmissionUnknown(): self
	{
		$copy = clone $this;
		$copy->omission = Certainty::UNKNOWN;

		return $copy;
	}

	public function withValueType(Type $valueType): self
	{
		$copy = clone $this;
		$copy->valueType = $valueType;

		return $copy;
	}

	public function withAcceptedSetSpec(string $acceptedSetSpec): self
	{
		$copy = clone $this;
		$copy->acceptedSetSpec = $acceptedSetSpec;

		return $copy;
	}

	public function getKind(): string
	{
		return $this->kind;
	}

	public function getValueType(): ?Type
	{
		return $this->valueType;
	}

	/** @return list<string> */
	public function getUnknownReasons(): array
	{
		return $this->unknownReasons;
	}

	public function getControlClass(): ?string
	{
		return $this->controlClass;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function isRequired(): bool
	{
		return $this->required;
	}

	public function getOmission(): string
	{
		return $this->omission;
	}

	public function getAcceptedSetSpec(): ?string
	{
		return $this->acceptedSetSpec;
	}

	public function getReplicatorFactoryArgPosition(): ?int
	{
		return $this->replicatorFactoryArgPosition;
	}

}
