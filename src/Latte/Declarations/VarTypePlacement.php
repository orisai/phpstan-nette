<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

// One mid-file {varType} tag together with the construct that immediately follows it, resolved at
// the Latte TOKEN level rather than off the compiled PHP: the pipeline rewrites the very shapes an
// anchor would be matched by (DeclarationInjector splices its own assignment at the tag's own line,
// CaptureEliminator/IteratorEliminator change what {capture}/{foreach} look like between pipeline
// stages), so a token-level answer is the only one that is stable regardless of stage.
//
// A HEADER {varType} and a {block}/{define}-body one are parameter declarations, not mid-file
// locals, and never become a VarTypePlacement at all.
final class VarTypePlacement
{

	public const ANCHOR_ASSIGN = 'assign';

	public const ANCHOR_FOREACH = 'foreach';

	public const ANCHOR_CAPTURE = 'capture';

	private string $name;

	private string $type;

	private int $line;

	/** @var self::ANCHOR_*|null */
	private ?string $anchorKind;

	private ?int $anchorLine;

	private string $anchorLabel;

	/** @var list<string>|null */
	private ?array $boundVariables;

	private int $runSize;

	private bool $neverAssigned;

	private bool $knownBeforeTag;

	private bool $insideBlockBody;

	/**
	 * @param self::ANCHOR_*|null $anchorKind
	 * @param list<string>|null $boundVariables
	 */
	public function __construct(
		string $name,
		string $type,
		int $line,
		?string $anchorKind,
		?int $anchorLine,
		string $anchorLabel,
		?array $boundVariables,
		int $runSize,
		bool $neverAssigned,
		bool $knownBeforeTag,
		bool $insideBlockBody
	)
	{
		$this->name = $name;
		$this->type = $type;
		$this->line = $line;
		$this->anchorKind = $anchorKind;
		$this->anchorLine = $anchorLine;
		$this->anchorLabel = $anchorLabel;
		$this->boundVariables = $boundVariables;
		$this->runSize = $runSize;
		$this->neverAssigned = $neverAssigned;
		$this->knownBeforeTag = $knownBeforeTag;
		$this->insideBlockBody = $insideBlockBody;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function getLine(): int
	{
		return $this->line;
	}

	/**
	 * @return self::ANCHOR_*|null
	 */
	public function getAnchorKind(): ?string
	{
		return $this->anchorKind;
	}

	public function getAnchorLine(): ?int
	{
		return $this->anchorLine;
	}

	// Human-readable description of whatever actually follows the tag ("{if}", "an output tag",
	// "the end of the template"), carried for the misplaced message even when an anchor WAS found.
	public function getAnchorLabel(): string
	{
		return $this->anchorLabel;
	}

	// null means the anchor binds an unresolvable set of variables (a {do}/{php} body this scanner
	// cannot reduce to plain variable assignments): accepted as an anchor, never name-checked.

	/**
	 * @return list<string>|null
	 */
	public function getBoundVariables(): ?array
	{
		return $this->boundVariables;
	}

	// How many {varType} tags share this anchor - PHPStan's own differentVariable/variableNotFound
	// split turns on both this and the number of assigned variables.
	public function getRunSize(): int
	{
		return $this->runSize;
	}

	// True only when the tag has NO anchor and nothing in the whole template ever binds its
	// variable: the signature of a template PARAMETER whose {varType} lost its header position to a
	// preceding non-header tag, rather than a local declaration written in the wrong place. False
	// whenever the answer is unknown (a construct whose bindings could not be resolved).
	public function isNeverAssigned(): bool
	{
		return $this->neverAssigned;
	}

	// True only for a non-foreach anchor whose mismatched name is already bound by something
	// earlier in the template (a header parameter or a prior assignment) - mirrors
	// WrongVariableNameInVarTagRule::processAssign()'s `hasVariableType()` guard, under which such
	// a name is accepted silently regardless of whether it matches THIS particular anchor.
	public function isKnownBeforeTag(): bool
	{
		return $this->knownBeforeTag;
	}

	// Whether the tag sits anywhere inside a NAMED {block}/{define}/{snippet}/{snippetArea} body,
	// which Latte compiles to its own block* method instead of into main(). The anchor a placement
	// is matched against must live in the same compiled method as the tag, and (line, kind, name)
	// alone does not say so - a whole {block} written on the declaration's own line puts a second
	// assignment to the same variable on the same line in a DIFFERENT method. See
	// LatteVarTypeExpressionRule::matchPlacement().
	public function isInsideBlockBody(): bool
	{
		return $this->insideBlockBody;
	}

}
