<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte2;

use Latte\Runtime\Defaults;
use Latte\Runtime\Filters;
use Nette\Utils\Strings;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Forms\FormSite;
use OriPhpstan\Nette\Latte\Includes\TagArgument;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use OriPhpstan\Nette\Latte\Version\CompiledTemplate;
use OriPhpstan\Nette\Latte\Version\DefaultCallables;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;

final class Latte2Adapter implements LatteVersionAdapter
{

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
		return ExtractedFacts::lazy(
			fn (): Declarations => $this->scanner->scan($source),
			fn (): TemplateFacts => $this->factExtractor->extract($source, $relativePath),
			/** @return list<FormSite> */
			fn (): array => $this->formSiteScanner->scan($source),
		);
	}

	public function lineMarkerPattern(): string
	{
		return $this->family->lineMarkerPattern();
	}

	public function family(): ShapeFamily
	{
		return $this->family;
	}

	// Defaults::getFilters() wraps a handful of entries in closures when an optional dependency
	// (mbstring, nette/utils) is missing; both are hard dependencies of this project so those
	// branches never execute here, but they are mapped by hand so resolution never depends on which
	// branch PHP happened to take.
	public function defaultCallables(): DefaultCallables
	{
		$defaults = new Defaults();

		return new DefaultCallables(
			$defaults->getFilters(),
			$defaults->getFunctions(),
			[
				'capitalize' => [Filters::class, 'capitalize'],
				'firstupper' => [Filters::class, 'firstUpper'],
				'lower' => [Filters::class, 'lower'],
				'upper' => [Filters::class, 'upper'],
				'webalize' => [Strings::class, 'webalize'],
			],
			[],
		);
	}

	/**
	 * @return list<TagArgument>
	 */
	public function parseTagArguments(string $argsSource): array
	{
		return MacroTokensArguments::parse($argsSource);
	}

}
