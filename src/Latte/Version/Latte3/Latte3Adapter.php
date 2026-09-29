<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use OriPhpstan\Nette\Latte\Version\AdapterCollaborators;
use OriPhpstan\Nette\Latte\Version\CompiledTemplate;
use OriPhpstan\Nette\Latte\Version\DefaultCallables;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;

// Holds no Latte 3 object itself: LatteVersionAdapterFactory may class_exists() and construct it
// where Latte 3 is not installed, so everything Latte-typed lives in the compiler's parse results.
final class Latte3Adapter implements LatteVersionAdapter
{

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
		$facts = $this->factsOf($parsed, $source, $relativePath);

		return new CompiledTemplate($this->compiler->generate($parsed, $className, $relativePath), $facts);
	}

	public function extractFacts(string $source, string $relativePath): ExtractedFacts
	{
		return $this->factsOf($this->compiler->parse($source), $source, $relativePath);
	}

	public function lineMarkerPattern(): string
	{
		return $this->family->lineMarkerPattern();
	}

	public function family(): ShapeFamily
	{
		return $this->family;
	}

	public function defaultCallables(): DefaultCallables
	{
		return $this->compiler->defaultCallables();
	}

	// Read before generate(): the passes mutate the parsed tree in place.
	private function factsOf(ParsedTemplate $parsed, string $source, string $relativePath): ExtractedFacts
	{
		return (new NodeFactsExtractor())->extract($parsed, $source, $relativePath);
	}

}
