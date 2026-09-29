<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte2;

use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\CompiledTemplate;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;

final class Latte2Adapter implements LatteVersionAdapter
{

	public const LINE_MARKER_PATTERN = '~/\* line (?<line>\d+) \*/~';

	private LatteCompiler $compiler;

	private DeclarationScanner $scanner;

	private TemplateFactExtractor $factExtractor;

	private FormSiteScanner $formSiteScanner;

	private ShapeFamily $family;

	public function __construct(
		LatteCompiler $compiler,
		DeclarationScanner $scanner,
		TemplateFactExtractor $factExtractor,
		FormSiteScanner $formSiteScanner
	)
	{
		$this->compiler = $compiler;
		$this->scanner = $scanner;
		$this->factExtractor = $factExtractor;
		$this->formSiteScanner = $formSiteScanner;
		$this->family = new ShapeFamily(ShapeFamily::LATTE_2, ShapeFamily::FORMS_MACROS);
	}

	public function compile(string $source, string $className, string $relativePath): CompiledTemplate
	{
		return new CompiledTemplate(
			$this->compiler->compile($source, $className, $this->family->id() . '|' . self::class),
			$this->extractFacts($source, $relativePath),
		);
	}

	public function extractFacts(string $source, string $relativePath): ExtractedFacts
	{
		return new ExtractedFacts(
			$this->scanner->scan($source),
			$this->factExtractor->extract($source, $relativePath),
			$this->formSiteScanner->scan($source),
		);
	}

	public function lineMarkerPattern(): string
	{
		return self::LINE_MARKER_PATTERN;
	}

	public function family(): ShapeFamily
	{
		return $this->family;
	}

}
