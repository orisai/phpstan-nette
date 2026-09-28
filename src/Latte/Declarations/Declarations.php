<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

final class Declarations
{

	private ?string $templateTypeClass;

	private ?int $templateTypeLine;

	/** @var array<string, string> */
	private array $headerVarTypes;

	/** @var array<string, int> */
	private array $headerVarTypeLines;

	/** @var array<int, array{string, string, int}> */
	private array $midFileVarTypes;

	/** @var array<int, array{string, string, int}> */
	private array $typedVars;

	/** @var array<int, array{string, string, int}> */
	private array $typedDefaults;

	/** @var array<int, array{string|null, string, string|null, int}>|null */
	private ?array $parameters;

	/** @var array<string, array<int, array{string|null, string}>> */
	private array $defineParams;

	/** @var array<string, array<string, true>> */
	private array $defineParamDefaults;

	/** @var list<VarTypePlacement> */
	private array $varTypePlacements;

	/**
	 * @param array<string, string> $headerVarTypes
	 * @param array<string, int> $headerVarTypeLines
	 * @param array<int, array{string, string, int}> $midFileVarTypes
	 * @param array<int, array{string, string, int}> $typedVars
	 * @param array<int, array{string, string, int}> $typedDefaults
	 * @param array<int, array{string|null, string, string|null, int}>|null $parameters
	 * @param array<string, array<int, array{string|null, string}>> $defineParams
	 * @param array<string, array<string, true>> $defineParamDefaults
	 * @param list<VarTypePlacement> $varTypePlacements
	 */
	public function __construct(
		?string $templateTypeClass,
		?int $templateTypeLine,
		array $headerVarTypes,
		array $headerVarTypeLines,
		array $midFileVarTypes,
		array $typedVars,
		array $typedDefaults,
		?array $parameters,
		array $defineParams,
		array $defineParamDefaults,
		array $varTypePlacements
	)
	{
		$this->templateTypeClass = $templateTypeClass;
		$this->templateTypeLine = $templateTypeLine;
		$this->headerVarTypes = $headerVarTypes;
		$this->headerVarTypeLines = $headerVarTypeLines;
		$this->midFileVarTypes = $midFileVarTypes;
		$this->typedVars = $typedVars;
		$this->typedDefaults = $typedDefaults;
		$this->parameters = $parameters;
		$this->defineParams = $defineParams;
		$this->defineParamDefaults = $defineParamDefaults;
		$this->varTypePlacements = $varTypePlacements;
	}

	// Only PLACEMENT-CHECKABLE {varType} tags: a header one (parameter for the whole file) and a
	// {block}/{define}-body one at that block's own top level (parameter for the block) are both
	// declarations of an incoming variable, never a mid-file local, and never appear here.

	/**
	 * @return list<VarTypePlacement>
	 */
	public function getVarTypePlacements(): array
	{
		return $this->varTypePlacements;
	}

	public function getTemplateTypeClass(): ?string
	{
		return $this->templateTypeClass;
	}

	public function getTemplateTypeLine(): ?int
	{
		return $this->templateTypeLine;
	}

	/**
	 * @return array<string, string>
	 */
	public function getHeaderVarTypes(): array
	{
		return $this->headerVarTypes;
	}

	/**
	 * @return array<string, int>
	 */
	public function getHeaderVarTypeLines(): array
	{
		return $this->headerVarTypeLines;
	}

	/**
	 * @return array<int, array{string, string, int}>
	 */
	public function getMidFileVarTypes(): array
	{
		return $this->midFileVarTypes;
	}

	/**
	 * @return array<int, array{string, string, int}>
	 */
	public function getTypedVars(): array
	{
		return $this->typedVars;
	}

	/**
	 * @return array<int, array{string, string, int}>
	 */
	public function getTypedDefaults(): array
	{
		return $this->typedDefaults;
	}

	/**
	 * @return array<int, array{string|null, string, string|null, int}>|null
	 */
	public function getParameters(): ?array
	{
		return $this->parameters;
	}

	/**
	 * @return array<string, array<int, array{string|null, string}>>
	 */
	public function getDefineParams(): array
	{
		return $this->defineParams;
	}

	// A block param carrying an explicit `= default` is optional at the call site (real Latte
	// generates a default PHP Param for it) - the missing-variable contract check must never
	// treat it as required. Keyed the same way as getDefineParams() (block name -> param name),
	// only the presence of a default matters, so this is a set, not a value map.

	/**
	 * @return array<string, array<string, true>>
	 */
	public function getDefineParamDefaults(): array
	{
		return $this->defineParamDefaults;
	}

}
