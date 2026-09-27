<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

/**
 * One component registration ContainerRegistrationDetector read out of a container method's body.
 *
 * What it records is only what the two consumers ask: which of the method's own parameters carries
 * the component's name (null when the name is a literal, a constant, a property, or anything else
 * the declaration does not parameterise), and whether the registration is reached unconditionally.
 */
final class DetectedRegistration
{

	private ?string $nameParameter;

	private ?int $nameParameterIndex;

	private bool $conditional;

	public function __construct(?string $nameParameter, ?int $nameParameterIndex, bool $conditional)
	{
		$this->nameParameter = $nameParameter;
		$this->nameParameterIndex = $nameParameterIndex;
		$this->conditional = $conditional;
	}

	public function getNameParameter(): ?string
	{
		return $this->nameParameter;
	}

	public function getNameParameterIndex(): ?int
	{
		return $this->nameParameterIndex;
	}

	public function isConditional(): bool
	{
		return $this->conditional;
	}

}
