<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

/**
 * One `@form-adds $param [ControlClass]` occurrence that survived validation: the parameter carrying
 * the component name, that parameter's position in the annotated method's signature, and the control
 * class the call registers — null when the tag names none and the declared return type is the source.
 *
 * The INDEX is resolved from the annotated method's own parameter list rather than stored in the tag,
 * so an override that reorders or renames around the same parameter name stays correct without the
 * docblock being touched.
 */
final class FormAddsSpec
{

	private string $parameterName;

	private int $parameterIndex;

	private ?string $controlClass;

	public function __construct(string $parameterName, int $parameterIndex, ?string $controlClass)
	{
		$this->parameterName = $parameterName;
		$this->parameterIndex = $parameterIndex;
		$this->controlClass = $controlClass;
	}

	public function getParameterName(): string
	{
		return $this->parameterName;
	}

	public function getParameterIndex(): int
	{
		return $this->parameterIndex;
	}

	public function getControlClass(): ?string
	{
		return $this->controlClass;
	}

}
