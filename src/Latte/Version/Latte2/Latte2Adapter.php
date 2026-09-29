<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte2;

use Latte\Engine;
use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ParsedTemplate;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;

final class Latte2Adapter implements LatteVersionAdapter
{

	public const LINE_MARKER_PATTERN = '~/\* line (?<line>\d+) \*/~';

	private LatteCompiler $compiler;

	private DeclarationScanner $scanner;

	private TemplateFactExtractor $factExtractor;

	private FormMacroCollector $formMacroCollector;

	private ShapeFamily $family;

	public function __construct(
		LatteCompiler $compiler,
		DeclarationScanner $scanner,
		TemplateFactExtractor $factExtractor,
		FormMacroCollector $formMacroCollector
	)
	{
		$this->compiler = $compiler;
		$this->scanner = $scanner;
		$this->factExtractor = $factExtractor;
		$this->formMacroCollector = $formMacroCollector;
		$this->family = new ShapeFamily(ShapeFamily::LATTE_2, ShapeFamily::FORMS_MACROS);
	}

	public function compile(string $source, string $className): CompileResult
	{
		return $this->compiler->compile($source, $className, $this->family->id() . '|' . self::class);
	}

	public function extractFacts(string $source, ParsedTemplate $parsed): ExtractedFacts
	{
		return new ExtractedFacts(
			$this->scanner->scan($source),
			$this->factExtractor->extract($source, $parsed->getRelativePath()),
			$this->formMacroCollector->sitesFor($parsed->getRelativePath()),
		);
	}

	public function harvestCustoms(Engine $engine): HarvestedCustoms
	{
		return CustomsHarvester::enumerate($engine);
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
