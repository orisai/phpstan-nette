<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Type;

use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

final class FormControlType extends ObjectType
{

	private Type $getValueType;

	private ?string $acceptedSetSpec;

	private bool $nullable;

	public function __construct(string $controlClass, Type $getValueType, ?string $acceptedSetSpec, bool $nullable)
	{
		$this->getValueType = $getValueType;
		$this->acceptedSetSpec = $acceptedSetSpec;
		$this->nullable = $nullable;
		parent::__construct($controlClass);
	}

	public function getFormGetValueType(): Type
	{
		return $this->getValueType;
	}

	public function getFormAcceptedSetSpec(): ?string
	{
		return $this->acceptedSetSpec;
	}

	public function equals(Type $type): bool
	{
		return $type instanceof self
			&& parent::equals($type)
			&& $this->getFormGetValueType()->equals($type->getFormGetValueType())
			&& $this->acceptedSetSpec === $type->acceptedSetSpec
			&& $this->nullable === $type->nullable;
	}

	public function describe(VerbosityLevel $level): string
	{
		return $this->getClassName();
	}

}
