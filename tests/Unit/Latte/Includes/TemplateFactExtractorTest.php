<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use OriPhpstan\Nette\Latte\Version\Latte2\TemplateFactExtractor;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * @group latte2
 */
final class TemplateFactExtractorTest extends BaseTestCase
{

	public function testStaticFileIncludeResolvedRelativeToReferringDir(): void
	{
		$facts = (new TemplateFactExtractor())->extract(
			"{include 'parts/row.latte', item => \$item}\n",
			'app/templates/News/detail.latte',
		);

		$sites = $facts->getIncludeSites();
		self::assertCount(1, $sites);
		self::assertSame('include', $sites[0]->getTag());
		self::assertSame(IncludeTarget::KIND_STATIC_FILE, $sites[0]->getKind());
		self::assertSame('app/templates/News/parts/row.latte', $sites[0]->getResolvedPath());
		self::assertSame(1, $sites[0]->getLatteLine());
		self::assertStringContainsString('item', $sites[0]->getArgsSource());
	}

	public function testParentTraversalNormalized(): void
	{
		$facts = (new TemplateFactExtractor())->extract(
			"{include '../_shared/menu.latte'}\n",
			'app/templates/News/detail.latte',
		);

		self::assertSame('app/templates/_shared/menu.latte', $facts->getIncludeSites()[0]->getResolvedPath());
	}

	public function testBlockIncludeAndDynamicInclude(): void
	{
		$source = "{include #head}\n{include \$row->getTemplate()}\n";
		$sites = (new TemplateFactExtractor())->extract($source, 'a/b.latte')->getIncludeSites();

		self::assertSame(IncludeTarget::KIND_STATIC_BLOCK, $sites[0]->getKind());
		self::assertSame('head', $sites[0]->getRawTarget());
		self::assertSame(IncludeTarget::KIND_DYNAMIC, $sites[1]->getKind());
		self::assertNull($sites[1]->getResolvedPath());
		self::assertSame(2, $sites[1]->getLatteLine());
	}

	public function testExplicitBlockKeywordForm(): void
	{
		$sites = (new TemplateFactExtractor())->extract("{include block head}\n", 'a/b.latte')->getIncludeSites();

		self::assertSame(IncludeTarget::KIND_STATIC_BLOCK, $sites[0]->getKind());
		self::assertSame('head', $sites[0]->getRawTarget());
	}

	public function testEmbedFileAndEmbedBlock(): void
	{
		$fileSites = (new TemplateFactExtractor())->extract(
			"{embed 'parts/x.latte'}\n",
			'app/y.latte',
		)->getIncludeSites();
		self::assertSame('embed', $fileSites[0]->getTag());
		self::assertSame(IncludeTarget::KIND_STATIC_FILE, $fileSites[0]->getKind());
		self::assertSame('app/parts/x.latte', $fileSites[0]->getResolvedPath());

		$blockSites = (new TemplateFactExtractor())->extract("{embed header}\n", 'app/y.latte')->getIncludeSites();
		self::assertSame(IncludeTarget::KIND_STATIC_BLOCK, $blockSites[0]->getKind());
		self::assertSame('header', $blockSites[0]->getRawTarget());
	}

	public function testImportAndSandboxAreFileOnly(): void
	{
		$importSites = (new TemplateFactExtractor())->extract(
			"{import 'shared/import.latte'}\n",
			'app/y.latte',
		)->getIncludeSites();
		self::assertSame('import', $importSites[0]->getTag());
		self::assertSame(IncludeTarget::KIND_STATIC_FILE, $importSites[0]->getKind());
		self::assertSame('app/shared/import.latte', $importSites[0]->getResolvedPath());

		$sandboxSites = (new TemplateFactExtractor())->extract(
			"{sandbox 'shared/sandbox.latte'}\n",
			'app/y.latte',
		)->getIncludeSites();
		self::assertSame('sandbox', $sandboxSites[0]->getTag());
		self::assertSame(IncludeTarget::KIND_STATIC_FILE, $sandboxSites[0]->getKind());
		self::assertSame('app/shared/sandbox.latte', $sandboxSites[0]->getResolvedPath());
	}

	public function testIncludeBlockIsFileOnly(): void
	{
		$sites = (new TemplateFactExtractor())->extract(
			"{includeblock 'shared/legacy.latte'}\n",
			'app/y.latte',
		)->getIncludeSites();

		self::assertSame('includeblock', $sites[0]->getTag());
		self::assertSame(IncludeTarget::KIND_STATIC_FILE, $sites[0]->getKind());
		self::assertSame('app/shared/legacy.latte', $sites[0]->getResolvedPath());
	}

	public function testLayoutAutoAndNoneSkippedStaticKept(): void
	{
		self::assertSame([], (new TemplateFactExtractor())->extract("{layout auto}\n", 'a/b.latte')->getIncludeSites());
		self::assertSame(
			[],
			(new TemplateFactExtractor())->extract("{extends none}\n", 'a/b.latte')->getIncludeSites(),
		);

		$sites = (new TemplateFactExtractor())->extract(
			"{layout '../@layout.latte'}\n",
			'app/x/y.latte',
		)->getIncludeSites();
		self::assertSame('layout', $sites[0]->getTag());
		self::assertSame('app/@layout.latte', $sites[0]->getResolvedPath());
	}

	// {layout none}/{layout auto} leave NO include site behind (asserted above), so the declaration
	// itself is the only surviving trace - and it is what decides whether vendor's presenter-side
	// auto-layout walk still applies to this template (see TemplateEdgeIndex::autoLayoutApplies()).
	public function testLayoutModeRecordsTheDeclarationEvenWhenNoSiteSurvives(): void
	{
		$extractor = new TemplateFactExtractor();

		self::assertNull($extractor->extract("plain body\n", 'a/b.latte')->getLayoutMode());
		self::assertSame(
			TemplateFacts::LAYOUT_MODE_NONE,
			$extractor->extract("{layout none}\n", 'a/b.latte')->getLayoutMode(),
		);
		self::assertSame(
			TemplateFacts::LAYOUT_MODE_NONE,
			$extractor->extract("{extends none}\n", 'a/b.latte')->getLayoutMode(),
		);
		self::assertSame(
			TemplateFacts::LAYOUT_MODE_AUTO,
			$extractor->extract("{layout auto}\n", 'a/b.latte')->getLayoutMode(),
		);
		self::assertSame(
			TemplateFacts::LAYOUT_MODE_DECLARED,
			$extractor->extract("{layout '../@layout.latte'}\n", 'app/x/y.latte')->getLayoutMode(),
		);
		self::assertSame(
			TemplateFacts::LAYOUT_MODE_DECLARED,
			$extractor->extract("{layout \$chosen}\n", 'app/x/y.latte')->getLayoutMode(),
		);
		self::assertNull($extractor->extract("{include 'x.latte'}\n", 'app/x/y.latte')->getLayoutMode());
	}

	public function testLayoutModeSurvivesTheArrayRoundTrip(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{layout none}\n", 'a/b.latte');

		self::assertSame(
			TemplateFacts::LAYOUT_MODE_NONE,
			TemplateFacts::fromArray($facts->toArray())->getLayoutMode(),
		);
	}

	public function testBlocksDefinesAndTopLevelVars(): void
	{
		$source = "{var \$a = 1}\n{block content}{var \$inner = 2}{/block}\n{define row}x{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(['content'], $facts->getBlockNames());
		self::assertSame(['row'], $facts->getDefineNames());
		self::assertSame(['a' => 'int'], $facts->getTopLevelVars());
	}

	public function testTopLevelVarLiteralTypes(): void
	{
		$source = "{var \$i = 1, \$f = 1.5, \$s = 'x', \$b = true, \$arr = [1, 2], \$m = 1 + 2, \$other = \$x}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame([
			'i' => 'int',
			'f' => 'float',
			's' => 'string',
			'b' => 'bool',
			'arr' => 'array',
			'm' => 'mixed',
			'other' => 'mixed',
		], $facts->getTopLevelVars());
	}

	public function testMalformedSourceDegradesToEmptyFacts(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{include \xC3\x28}\n", 'a/b.latte');

		self::assertSame([], $facts->getIncludeSites());
		self::assertSame([], $facts->getBlockNames());
	}

	public function testFactsRoundTripThroughArrays(): void
	{
		$facts = (new TemplateFactExtractor())->extract(
			"{include 'x.latte', a => 1}\n{block b}{/block}\n",
			'd/e.latte',
		);

		self::assertEquals($facts, TemplateFacts::fromArray($facts->toArray()));
	}

	public function testGettextShorthandDoesNotBreakDepthTracking(): void
	{
		$source = "{_'Some translated text'}\n{var \$x = 1}\n";
		self::assertSame(
			['x' => 'int'],
			(new TemplateFactExtractor())->extract($source, 'a/b.latte')->getTopLevelVars(),
		);
	}

	// g_/ng_/dg_/dng_ (h4kuna\Gettext\Macros\Gettext, installed natively as of the harvested-macros
	// task) register with a single addMacro() argument ($end === null), so Latte always treats them
	// as self-closing - unlike CoreMacros' own '_' (conditionally a pair tag when args are empty,
	// see isGettextShorthand()). None of the four belong in PairedTags::NAMES; this re-validates that a
	// {var} right after one stays at depth 0 regardless, proving the rider needs no change for the
	// wider family even though extraction never touches the compiler/installed macro sets at all.
	public function testGettextFamilyShorthandNamesNeverOpenABodyRegardlessOfArgs(): void
	{
		$source = "{g_'Hi'}\n{ng_ 'one', 'many', \$n}\n{dg_ 'd', 'x'}\n{dng_ 'd', 'one', 'many', \$n}\n{var \$after = 1}\n";

		self::assertSame(
			['after' => 'int'],
			(new TemplateFactExtractor())->extract($source, 'a/b.latte')->getTopLevelVars(),
		);
	}

	public function testPairFormTranslateStillNests(): void
	{
		$source = "{_}text{var \$inner = 1}{/_}\n{var \$outer = 2}\n";
		self::assertSame(
			['outer' => 'int'],
			(new TemplateFactExtractor())->extract($source, 'a/b.latte')->getTopLevelVars(),
		);
	}

	public function testEmptyGenericCloseDecrementsDepth(): void
	{
		$source = "{if true}{var \$in = 1}{/}\n{var \$out = 2}\n";
		self::assertSame(
			['out' => 'int'],
			(new TemplateFactExtractor())->extract($source, 'a/b.latte')->getTopLevelVars(),
		);
	}

	public function testParentTraversalBeyondRootClamps(): void
	{
		$sites = (new TemplateFactExtractor())->extract("{include '../../up.latte'}\n", 'top.latte')->getIncludeSites();
		self::assertSame('up.latte', $sites[0]->getResolvedPath());
	}

	public function testBareLabelWithoutCloseDoesNotBreakDepthTracking(): void
	{
		$source = "{label foo /}\n{label bar}\n{var \$x = 1}\n";
		self::assertSame(
			['x' => 'int'],
			(new TemplateFactExtractor())->extract($source, 'a/b.latte')->getTopLevelVars(),
		);
	}

	public function testPairedLabelStillNests(): void
	{
		$source = "{label foo}{var \$inner = 1}{/label}\n{var \$outer = 2}\n";
		self::assertSame(
			['outer' => 'int'],
			(new TemplateFactExtractor())->extract($source, 'a/b.latte')->getTopLevelVars(),
		);
	}

	public function testTopLevelDefaultsCaptured(): void
	{
		$source = "{default \$a = 1}\n{default int \$b = 2, \$c = 3}\n";
		self::assertSame(
			['a', 'b', 'c'],
			(new TemplateFactExtractor())->extract($source, 'x/y.latte')->getTopLevelDefaults(),
		);
	}

	public function testNestedDefaultNotTopLevel(): void
	{
		$source = "{if true}{default \$inner = 1}{/if}\n{default \$outer = 2}\n";
		self::assertSame(
			['outer'],
			(new TemplateFactExtractor())->extract($source, 'x/y.latte')->getTopLevelDefaults(),
		);
	}

	public function testSwitchDefaultNotATopLevelDefault(): void
	{
		$source = "{switch \$x}{case 1}one{default}other{/switch}\n";
		self::assertSame([], (new TemplateFactExtractor())->extract($source, 'x/y.latte')->getTopLevelDefaults());
	}

	public function testDefaultsRoundTripThroughArrays(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{default \$d = 1}\n", 'x/y.latte');
		self::assertEquals($facts, TemplateFacts::fromArray($facts->toArray()));
	}

	public function testDefineBodyVarTypeExtracted(): void
	{
		$source = "{define b}{varType string \$x}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(['b' => ['x' => 'string']], $facts->getBlockDeclaredVars());
	}

	public function testBlockBodyVarTypeExtracted(): void
	{
		$source = "{block b}{varType string \$x}{/block}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(['b' => ['x' => 'string']], $facts->getBlockDeclaredVars());
	}

	// An anonymous {block} (no name at all) is never a call target, so it gets no frame from the
	// shared BlockBodyTracker either - matching DeclarationScanner's own placement-checker side of
	// this exact gate (see VarTypePlacementCheckerTest::testAnonymousBlockBodyVarTypeIsNotExempt).
	public function testAnonymousBlockBodyVarTypeIsNotRecordedAsADeclaredVar(): void
	{
		$source = "{block}{varType string \$x}{/block}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame([], $facts->getBlockDeclaredVars());
	}

	public function testDynamicallyNamedDefineBodyVarTypeIsNotRecordedAsADeclaredVar(): void
	{
		$source = "{var \$name = 'b'}\n{define \$name}{varType string \$x}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame([], $facts->getBlockDeclaredVars());
	}

	public function testConditionalVarTypeInsideBlockBodyExcluded(): void
	{
		$source = "{define b}{if \$c}{varType string \$x}{/if}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame([], $facts->getBlockDeclaredVars());
	}

	public function testNestedDefineVarTypesKeyedIndependently(): void
	{
		$source = "{define outer}{varType string \$a}{define inner}{varType int \$b}{/define}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(
			['outer' => ['a' => 'string'], 'inner' => ['b' => 'int']],
			$facts->getBlockDeclaredVars(),
		);
	}

	public function testTopLevelVarsUnaffectedByBlockBodyDeclarations(): void
	{
		$source = "{var \$top = 1}\n{define b}{varType string \$x}{var \$inner = 2}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(['top' => 'int'], $facts->getTopLevelVars());
		self::assertSame(['b' => ['x' => 'string']], $facts->getBlockDeclaredVars());
	}

	public function testBlockBodyVarTypeReconcatenatesPipeModifierSplit(): void
	{
		$source = "{define b}{varType int|null \$x}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(['b' => ['x' => 'int|null']], $facts->getBlockDeclaredVars());
	}

	public function testGettextShorthandInsideBlockBodyDoesNotBreakVarTypeDepth(): void
	{
		$source = "{define b}{_'x'}{varType string \$v}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(['b' => ['v' => 'string']], $facts->getBlockDeclaredVars());
	}

	public function testUnknownBlockNameYieldsNoDeclaredVarsEntry(): void
	{
		$source = "{define b}{varType string \$x}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertArrayNotHasKey('nope', $facts->getBlockDeclaredVars());
	}

	public function testBlockDeclaredVarsRoundTripThroughArrays(): void
	{
		$facts = (new TemplateFactExtractor())->extract(
			"{define b}{varType string \$x}{/define}\n",
			'a/b.latte',
		);
		self::assertEquals($facts, TemplateFacts::fromArray($facts->toArray()));
	}

	public function testBlockDeclaredVarLineRecordedForDeclarationConsistencyReporting(): void
	{
		$source = "text\n{define b}\n{varType string \$x}\n{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(['b' => ['x' => 3]], $facts->getBlockDeclaredVarLines());
	}

	public function testNestedDefineVarTypeLinesKeyedIndependently(): void
	{
		$source = "{define outer}\n{varType string \$a}\n{define inner}\n{varType int \$b}\n{/define}{/define}\n";
		$facts = (new TemplateFactExtractor())->extract($source, 'a/b.latte');

		self::assertSame(
			['outer' => ['a' => 2], 'inner' => ['b' => 4]],
			$facts->getBlockDeclaredVarLines(),
		);
	}

	public function testGettextShorthandRecordedInLineMacros(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{_'Hello'}\n", 'a/b.latte');

		self::assertSame([1 => [['name' => '_', 'column' => 1]]], $facts->getLineMacros());
	}

	// {$expr} (and its explicit {=$expr} spelling) is handwritten code the developer already
	// sees at that exact line - not a hidden macro transformation - so it must never surface as a
	// provenance-tip source.
	public function testImplicitPrintTagExcludedFromLineMacros(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{\$x}\n{=\$y}\n", 'a/b.latte');

		self::assertSame([], $facts->getLineMacros());
	}

	public function testClosingTagNeverContributesToLineMacros(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{if true}\nx\n{/if}\n", 'a/b.latte');

		self::assertSame([1 => [['name' => 'if', 'column' => 1]]], $facts->getLineMacros());
	}

	public function testMultipleDistinctMacrosOnOneLineListedInSourceOrderWithColumns(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{if true}{_'x'}{/if}\n", 'a/b.latte');

		self::assertSame(
			[1 => [['name' => 'if', 'column' => 1], ['name' => '_', 'column' => 10]]],
			$facts->getLineMacros(),
		);
	}

	// Owner enhancement (task 4b review): columns exist precisely because the SAME macro name
	// twice on one line is two distinct locations - collapsing them (the old behaviour) would
	// throw away that precision.
	public function testRepeatedMacroOnOneLineListedAtEachOccurrenceWithItsOwnColumn(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{_'a'}{_'b'}\n", 'a/b.latte');

		self::assertSame(
			[1 => [['name' => '_', 'column' => 1], ['name' => '_', 'column' => 7]]],
			$facts->getLineMacros(),
		);
	}

	public function testColumnAccountsForPrecedingTextOnTheSameLine(): void
	{
		$facts = (new TemplateFactExtractor())->extract("Hello, {_'x'}!\n", 'a/b.latte');

		self::assertSame([1 => [['name' => '_', 'column' => 8]]], $facts->getLineMacros());
	}

	public function testColumnResetsAfterANewline(): void
	{
		$facts = (new TemplateFactExtractor())->extract("abc\n  {_'x'}\n", 'a/b.latte');

		self::assertSame([2 => [['name' => '_', 'column' => 3]]], $facts->getLineMacros());
	}

	public function testLineMacrosRoundTripThroughArrays(): void
	{
		$facts = (new TemplateFactExtractor())->extract("{if true}{_'x'}{/if}\n{var \$a = 1}\n", 'a/b.latte');

		self::assertEquals($facts, TemplateFacts::fromArray($facts->toArray()));
	}

}
