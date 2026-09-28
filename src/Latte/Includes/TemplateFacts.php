<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

final class TemplateFacts
{

	public const LAYOUT_MODE_NONE = 'none';

	public const LAYOUT_MODE_AUTO = 'auto';

	public const LAYOUT_MODE_DECLARED = 'declared';

	/** @var list<IncludeTarget> */
	private array $includeSites;

	/** @var list<string> */
	private array $blockNames;

	/** @var list<string> */
	private array $defineNames;

	/** @var array<string, string> */
	private array $topLevelVars;

	/** @var list<string> */
	private array $topLevelDefaults;

	/** @var array<string, array<string, string>> */
	private array $blockDeclaredVars;

	/** @var array<string, array<string, int>> */
	private array $blockDeclaredVarLines;

	/** @var array<int, list<array{name: string, column: int}>> */
	private array $lineMacros;

	/** @var self::LAYOUT_MODE_*|null */
	private ?string $layoutMode;

	/**
	 * @param list<IncludeTarget> $includeSites
	 * @param list<string> $blockNames
	 * @param list<string> $defineNames
	 * @param array<string, string> $topLevelVars
	 * @param list<string> $topLevelDefaults
	 * @param array<string, array<string, string>> $blockDeclaredVars
	 * @param array<string, array<string, int>> $blockDeclaredVarLines
	 * @param array<int, list<array{name: string, column: int}>> $lineMacros
	 * @param self::LAYOUT_MODE_*|null $layoutMode
	 */
	public function __construct(
		array $includeSites,
		array $blockNames,
		array $defineNames,
		array $topLevelVars,
		array $topLevelDefaults,
		array $blockDeclaredVars,
		array $blockDeclaredVarLines,
		array $lineMacros,
		?string $layoutMode = null
	)
	{
		$this->includeSites = $includeSites;
		$this->blockNames = $blockNames;
		$this->defineNames = $defineNames;
		$this->topLevelVars = $topLevelVars;
		$this->topLevelDefaults = $topLevelDefaults;
		$this->blockDeclaredVars = $blockDeclaredVars;
		$this->blockDeclaredVarLines = $blockDeclaredVarLines;
		$this->lineMacros = $lineMacros;
		$this->layoutMode = $layoutMode;
	}

	/**
	 * @param array{includeSites: list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>, blockNames: list<string>, defineNames: list<string>, topLevelVars: array<string, string>, topLevelDefaults: list<string>, blockDeclaredVars: array<string, array<string, string>>, blockDeclaredVarLines: array<string, array<string, int>>, lineMacros: array<int, list<array{name: string, column: int}>>, layoutMode: self::LAYOUT_MODE_*|null} $data
	 */
	public static function fromArray(array $data): self
	{
		$includeSites = [];
		foreach ($data['includeSites'] as $site) {
			$includeSites[] = IncludeTarget::fromArray($site);
		}

		return new self(
			$includeSites,
			$data['blockNames'],
			$data['defineNames'],
			$data['topLevelVars'],
			$data['topLevelDefaults'],
			$data['blockDeclaredVars'],
			$data['blockDeclaredVarLines'],
			$data['lineMacros'],
			$data['layoutMode'],
		);
	}

	/**
	 * @return list<IncludeTarget>
	 */
	public function getIncludeSites(): array
	{
		return $this->includeSites;
	}

	/**
	 * @return list<string>
	 */
	public function getBlockNames(): array
	{
		return $this->blockNames;
	}

	/**
	 * @return list<string>
	 */
	public function getDefineNames(): array
	{
		return $this->defineNames;
	}

	/**
	 * @return array<string, string>
	 */
	public function getTopLevelVars(): array
	{
		return $this->topLevelVars;
	}

	/**
	 * @return list<string>
	 */
	public function getTopLevelDefaults(): array
	{
		return $this->topLevelDefaults;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public function getBlockDeclaredVars(): array
	{
		return $this->blockDeclaredVars;
	}

	// Per-block, per-variable {varType} line (keyed the same way as getBlockDeclaredVars()) -
	// the override side of DeclarationConsistencyChecker's matrix reports at this line, never at
	// the native (param) declaration's line.

	/**
	 * @return array<string, array<string, int>>
	 */
	public function getBlockDeclaredVarLines(): array
	{
		return $this->blockDeclaredVarLines;
	}

	// Latte source line -> every macro tag occurrence on that line (name + 1-indexed column,
	// source order, never deduped - two same-named tags at different columns are distinct
	// locations), from the fact extractor's own MACRO_TAG tokenization walk -
	// LatteProvenanceTipRule's line-precise attribution source for macro-generated code
	// (FilterRewriter's node attribute covers the node-precise filter case instead). The
	// implicit-print tag ('=', {$expr}'s own compiled form) never appears here: that syntax is
	// handwritten code the developer already sees, not a hidden macro transformation.

	/**
	 * @return array<int, list<array{name: string, column: int}>>
	 */
	public function getLineMacros(): array
	{
		return $this->lineMacros;
	}

	// null = the template declares no {layout}/{extends} at all, which is the ONLY state (besides
	// LAYOUT_MODE_AUTO) that leaves vendor's presenter-side auto-layout walk free to run - see
	// TemplateEdgeIndex's auto-layout ingestion and LayoutSuppressionParityTest for the runtime proof.

	/**
	 * @return self::LAYOUT_MODE_*|null
	 */
	public function getLayoutMode(): ?string
	{
		return $this->layoutMode;
	}

	/**
	 * @return array{includeSites: list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>, blockNames: list<string>, defineNames: list<string>, topLevelVars: array<string, string>, topLevelDefaults: list<string>, blockDeclaredVars: array<string, array<string, string>>, blockDeclaredVarLines: array<string, array<string, int>>, lineMacros: array<int, list<array{name: string, column: int}>>, layoutMode: self::LAYOUT_MODE_*|null}
	 */
	public function toArray(): array
	{
		$includeSites = [];
		foreach ($this->includeSites as $site) {
			$includeSites[] = $site->toArray();
		}

		return [
			'includeSites' => $includeSites,
			'blockNames' => $this->blockNames,
			'defineNames' => $this->defineNames,
			'topLevelVars' => $this->topLevelVars,
			'topLevelDefaults' => $this->topLevelDefaults,
			'blockDeclaredVars' => $this->blockDeclaredVars,
			'blockDeclaredVarLines' => $this->blockDeclaredVarLines,
			'lineMacros' => $this->lineMacros,
			'layoutMode' => $this->layoutMode,
		];
	}

}
