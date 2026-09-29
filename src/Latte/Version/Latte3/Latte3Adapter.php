<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use OriPhpstan\Nette\Latte\Version\AdapterCollaborators;
use OriPhpstan\Nette\Latte\Version\CompiledTemplate;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;

// Holds no Latte 3 object itself: LatteVersionAdapterFactory may class_exists() and construct it
// where Latte 3 is not installed, so everything Latte-typed lives in the compiler's parse results.
final class Latte3Adapter implements LatteVersionAdapter
{

	// Latte 3.0's TemplateGenerator marks lines as `/* line N */`, 3.1's as `/* pos L:C */`.
	public const LINE_MARKER_PATTERN_30 = '~/\* line (?<line>\d+) \*/~';

	public const LINE_MARKER_PATTERN_31 = '~/\* pos (?<line>\d+):\d+ \*/~';

	private Latte3Compiler $compiler;

	private ShapeFamily $family;

	public function __construct(Latte3Compiler $compiler, ShapeFamily $family)
	{
		$this->compiler = $compiler;
		$this->family = $family;
	}

	public static function create(ShapeFamily $family, AdapterCollaborators $collaborators): self
	{
		return new self(new Latte3Compiler(), $family);
	}

	public function compile(string $source, string $className, string $relativePath): CompiledTemplate
	{
		$parsed = $this->compiler->parse($source);
		$facts = $this->factsOf($parsed);

		return new CompiledTemplate($this->compiler->generate($parsed, $className, $relativePath), $facts);
	}

	public function extractFacts(string $source, string $relativePath): ExtractedFacts
	{
		return $this->factsOf($this->compiler->parse($source));
	}

	public function lineMarkerPattern(): string
	{
		return $this->family->latteLine === ShapeFamily::LATTE_30
			? self::LINE_MARKER_PATTERN_30
			: self::LINE_MARKER_PATTERN_31;
	}

	public function family(): ShapeFamily
	{
		return $this->family;
	}

	// Read before generate(): the passes mutate the parsed tree in place.
	private function factsOf(ParsedTemplate $parsed): ExtractedFacts
	{
		return ExtractedFacts::eager(
			$this->declarationsOf($parsed->getDeclarations()),
			new TemplateFacts([], [], [], [], [], [], [], []),
			[],
		);
	}

	/**
	 * @param list<CapturedDeclaration> $captured
	 */
	private function declarationsOf(array $captured): Declarations
	{
		$templateTypeClass = null;
		$templateTypeLine = null;
		$headerVarTypes = [];
		$headerVarTypeLines = [];
		$midFileVarTypes = [];
		$parameters = null;

		foreach ($captured as $declaration) {
			$type = $declaration->getType();
			$variable = $declaration->getVariable();

			switch ($declaration->getKind()) {
				case CapturedDeclaration::TEMPLATE_TYPE:
					if ($templateTypeClass === null && $type !== null) {
						$templateTypeClass = $type;
						$templateTypeLine = $declaration->getLine();
					}

					break;
				case CapturedDeclaration::VAR_TYPE:
					if ($type === null || $variable === null) {
						break;
					}

					if ($declaration->isInHead()) {
						$headerVarTypes[$variable] = $type;
						$headerVarTypeLines[$variable] = $declaration->getLine();
					} else {
						$midFileVarTypes[] = [$variable, $type, $declaration->getLine()];
					}

					break;
				case CapturedDeclaration::PARAMETER:
					if ($variable === null) {
						break;
					}

					$parameters[] = [$type, $variable, $declaration->getDefault(), $declaration->getLine()];

					break;
			}
		}

		return new Declarations(
			$templateTypeClass,
			$templateTypeLine,
			$headerVarTypes,
			$headerVarTypeLines,
			$midFileVarTypes,
			[],
			[],
			$parameters,
			[],
			[],
			[],
		);
	}

}
