<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

// Every step that reads Latte's own API or depends on the shape of its generated code goes through
// here; the rest of the analysis sees only this library's own value objects. compile() yields the
// generated code AND the template facts from the same parse of $source; extractFacts() is the
// facts-only path for consumers that never need the code.
interface LatteVersionAdapter
{

	public function compile(string $source, string $className, string $relativePath): CompiledTemplate;

	public function extractFacts(string $source, string $relativePath): ExtractedFacts;

	// PCRE matching one generated line's source-position marker, the source line in a named group <line>.
	public function lineMarkerPattern(): string;

	public function family(): ShapeFamily;

	// The stock filters and functions of the installed line, the ones the compiled code can call
	// without the application registering anything.
	public function defaultCallables(): DefaultCallables;

}
