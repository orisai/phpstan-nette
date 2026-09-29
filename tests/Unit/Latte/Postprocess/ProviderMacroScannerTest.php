<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Postprocess\ProviderMacroScanner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use function array_column;

// Pins WHICH compiled shapes ProviderMacroScanner recognizes, independently of
// ProviderAvailabilityCheckerTest's own all-renderers guard - this file is only about detection,
// never about whether a finding is actually reported for a given renderer set.
final class ProviderMacroScannerTest extends BaseTestCase
{

	public function testStaticControlNameIsDetected(): void
	{
		$sites = $this->sitesFor("{control foo}\n");

		self::assertSame([ProviderMacroScanner::MACRO_CONTROL], array_column($sites, 'macro'));
		self::assertSame([ProviderMacroScanner::PROVIDER_UI_CONTROL], array_column($sites, 'provider'));
		self::assertSame(1, $sites[0]['line']);
	}

	// {control $var} collapses into the SAME Helpers::component() UiMacroEliminator itself produces
	// for the static-name shape - see ProviderMacroScanner's own class doc for why this is read the
	// same way rather than re-deriving the is_object() ambiguity UiMacroEliminator already erases.
	public function testDynamicControlNameIsAlsoDetected(): void
	{
		$sites = $this->sitesFor("{varType string \$foo}\n{control \$foo}\n");

		self::assertSame([ProviderMacroScanner::MACRO_CONTROL], array_column($sites, 'macro'));
	}

	public function testLinkMacroIsDetected(): void
	{
		$sites = $this->sitesFor("{link Homepage:default}\n");

		self::assertSame([ProviderMacroScanner::MACRO_LINK], array_column($sites, 'macro'));
		self::assertSame([ProviderMacroScanner::PROVIDER_UI_CONTROL], array_column($sites, 'provider'));
	}

	public function testNHrefAttributeIsDetected(): void
	{
		$sites = $this->sitesFor("<a n:href=\"Homepage:default\">x</a>\n");

		self::assertSame([ProviderMacroScanner::MACRO_LINK], array_column($sites, 'macro'));
	}

	public function testPlinkMacroIsDetected(): void
	{
		$sites = $this->sitesFor("{plink Homepage:default}\n");

		self::assertSame([ProviderMacroScanner::MACRO_PLINK], array_column($sites, 'macro'));
		self::assertSame([ProviderMacroScanner::PROVIDER_UI_PRESENTER], array_column($sites, 'provider'));
	}

	public function testIfCurrentWithDestinationIsDetected(): void
	{
		InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		$sites = $this->sitesFor("{ifCurrent Homepage:default}yes{/ifCurrent}\n");

		self::assertSame([ProviderMacroScanner::MACRO_IF_CURRENT], array_column($sites, 'macro'));
		self::assertSame([ProviderMacroScanner::PROVIDER_UI_PRESENTER], array_column($sites, 'provider'));
	}

	public function testBareIfCurrentIsDetected(): void
	{
		InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		$sites = $this->sitesFor("{ifCurrent}yes{/ifCurrent}\n");

		self::assertSame([ProviderMacroScanner::MACRO_IF_CURRENT], array_column($sites, 'macro'));
	}

	public function testStringFormIsDetected(): void
	{
		$sites = $this->sitesFor("{form myForm}{/form}\n");

		self::assertSame([ProviderMacroScanner::MACRO_FORM], array_column($sites, 'macro'));
		self::assertSame([ProviderMacroScanner::PROVIDER_UI_CONTROL], array_column($sites, 'provider'));
		self::assertSame(1, $sites[0]['line']);
	}

	// THE FALSE-POSITIVE TRAP this scanner's two-pass split exists to avoid: {form $var} compiles to
	// is_object($var) ? $var : $this->global->uiControl[$var], so a raw scan keyed on the uiControl
	// ArrayDimFetch alone (the same shape the static-name push above also uses) would flag an object
	// form too - one that never touches uiControl at runtime when $var really is a Form instance, the
	// common and intended use of this shape. FormsMacroEliminator's own Helpers::formObject() output
	// is what proves this is the object shape, never a name lookup.
	public function testObjectVariableFormIsNotDetected(): void
	{
		$sites = $this->sitesFor(
			"{varType Nette\\Application\\UI\\Form \$myForm}\n{form \$myForm}{/form}\n",
		);

		self::assertSame([], $sites);
	}

	public function testSnippetIsDetected(): void
	{
		$sites = $this->sitesFor("{snippet foo}bar{/snippet}\n");

		self::assertSame([ProviderMacroScanner::MACRO_SNIPPET], array_column($sites, 'macro'));
		self::assertSame([ProviderMacroScanner::PROVIDER_SNIPPET_DRIVER], array_column($sites, 'provider'));
	}

	public function testNSnippetAttributeIsDetected(): void
	{
		$sites = $this->sitesFor("<div n:snippet=\"foo\">bar</div>\n");

		self::assertSame([ProviderMacroScanner::MACRO_SNIPPET], array_column($sites, 'macro'));
	}

	public function testSnippetAreaIsDetected(): void
	{
		$sites = $this->sitesFor("{snippetArea foo}{snippet bar}baz{/snippet}{/snippetArea}\n");

		self::assertSame(
			[ProviderMacroScanner::MACRO_SNIPPET, ProviderMacroScanner::MACRO_SNIPPET],
			array_column($sites, 'macro'),
		);
	}

	public function testPlainTemplateHasNoSites(): void
	{
		self::assertSame([], $this->sitesFor("<p>{\$x}</p>\n"));
	}

	// F3: AnalysisPipeline gates BOTH scanRaw() (the {control} site here) and scanForms() (the
	// {form} site) behind $providerScanEnabled, the same precedent as $narrowingEnabled's
	// edgeAnchorInjector gate - a template with genuine sites of both shapes must still see the
	// full walk skipped, never a half-disabled one.
	public function testSitesAreEmptyWhenProviderScanIsDisabled(): void
	{
		$sites = $this->sitesFor("{control foo}\n{form myForm}{/form}\n", false);

		self::assertSame([], $sites);
	}

	/**
	 * @return list<array{macro: string, provider: string, line: int}>
	 */
	private function sitesFor(string $latte, bool $providerScanEnabled = true): array
	{
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($latte);
		$pipeline = PipelineFactory::create(null, null, true, null, $providerScanEnabled);
		$pipeline->process($compiled, $declarations);

		return $pipeline->getProviderMacroSites();
	}

}
