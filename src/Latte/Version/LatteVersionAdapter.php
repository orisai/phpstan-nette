<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use Latte\Engine;
use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;

// Every step that reads Latte's own API or depends on the shape of its generated code goes through
// here; the rest of the analysis sees only this library's own value objects.
interface LatteVersionAdapter
{

	public function compile(string $source, string $className): CompileResult;

	public function extractFacts(string $source, ParsedTemplate $parsed): ExtractedFacts;

	public function harvestCustoms(Engine $engine): HarvestedCustoms;

	// PCRE matching one generated line's source-position marker, the source line in a named group <line>.
	public function lineMarkerPattern(): string;

	public function family(): ShapeFamily;

}
