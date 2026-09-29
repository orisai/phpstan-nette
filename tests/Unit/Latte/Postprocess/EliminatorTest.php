<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\AttrShellEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\BlockDispatchEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\CaptureEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\ControlFlowEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\DevTagEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EliminatorVisitor;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EscapingEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FamilyPatterns;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FormsMacroEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\IteratorEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\PrologEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\UiMacroEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function substr_count;

final class EliminatorTest extends BaseTestCase
{

	public function testEveryEliminatorDescribesItsPattern(): void
	{
		foreach (ShapeFamily::all() as $family) {
			/** @var array<EliminatorVisitor> $eliminators */
			$eliminators = [
				new AttrShellEliminator($family),
				new BlockDispatchEliminator($family),
				new CaptureEliminator($family),
				new ControlFlowEliminator($family),
				new DevTagEliminator($family),
				new EscapingEliminator($family),
				new FormsMacroEliminator($family),
				new IteratorEliminator($family),
				new PrologEliminator($family),
				new UiMacroEliminator($family),
			];

			foreach ($eliminators as $eliminator) {
				self::assertNotSame('', $eliminator->describePattern(), $family->id());
			}
		}
	}

	public function testGetDefinedVarsReturnGone(): void
	{
		$php = $this->process("{varType bool \$a}\n{if \$a}x{/if}\n");

		self::assertStringNotContainsString('get_defined_vars', $php);
		if (InstalledVersionsGuard::latteMajor() === 2) {
			self::assertStringContainsString('return []', $php);
		}
	}

	public function testEscapingUnwrapped(): void
	{
		$php = $this->process("{varType string \$s}\n<a title=\"{\$s}\">{\$s}</a>\n");

		self::assertStringNotContainsString('escapeHtml', $php);
		self::assertStringContainsString('echo $s', $php);
	}

	public function testNestedEscapingUnwrapped(): void
	{
		$php = $this->process("{varType string \$url}\n<a href=\"{\$url}\">x</a>\n");

		self::assertStringNotContainsString('escapeHtmlAttr', $php);
		self::assertStringNotContainsString('safeUrl', $php);
		self::assertStringContainsString('echo $url', $php);
	}

	public function testEscapeJsStaysWrapped(): void
	{
		$php = $this->process(
			"{varType array<string, string> \$data}\n{varType object \$crate}\n"
			. "<script>var a = {\$data}; var b = {\$crate};</script>\n",
		);

		self::assertSame(2, substr_count($php, 'escapeJs('));
		self::assertStringNotContainsString('echo $data;', $php);
		self::assertStringNotContainsString('echo $crate;', $php);
	}

	public function testDumpDropped(): void
	{
		$php = $this->process("{varType string \$s}\n{dump \$s}\n");

		self::assertStringNotContainsString('barDump', $php);
	}

	public function testDumpWithoutArgsDropped(): void
	{
		$php = $this->process("{dump}\n");

		self::assertStringNotContainsString('barDump', $php);
	}

	public function testDumpArgumentExpressionKeptForAnalysis(): void
	{
		$php = $this->process("{varType string \$x}\n{dump \$x}\n");

		self::assertStringNotContainsString('barDump', $php);
		self::assertStringContainsString('Helpers::analyzed($x', $php);
		self::assertStringNotContainsString('$x;', $php);
	}

	public function testTraceDropped(): void
	{
		$php = $this->process("{trace}\n");

		self::assertStringNotContainsString('Tracer', $php);
	}

	// Latte 2 emits printClass(); exit; into prepare(), Latte 3 into the head the injector folds into
	// latteMain: the body stays reachable and a prepare left empty by the drop goes with it.
	public function testTemplatePrintDroppedKeepsTheBodyReachable(): void
	{
		$php = $this->process("{templatePrint}\n{varType string \$x}\n{\$x}\n");

		self::assertStringNotContainsString('printClass', $php);
		self::assertStringNotContainsString('exit', $php);
		self::assertStringNotContainsString('function lattePrepare', $php);
		self::assertStringContainsString('echo $x', $php);
	}

	public function testExtendsGuardAndBlockDispatchGetDefinedVars(): void
	{
		$php = $this->process("{block content}x{/block}\n");

		self::assertStringNotContainsString('getParentName', $php);
		self::assertSame(InstalledVersionsGuard::latteMajor() === 2 ? 1 : 0, substr_count($php, 'return []'));
		self::assertStringNotContainsString('renderBlock', $php);
		self::assertStringNotContainsString('get_defined_vars', $php);
		self::assertStringContainsString('$this->blockContent();', $php);
	}

	public function testForeachOverwriteWarningDropped(): void
	{
		$php = $this->process("{varType array \$items}\n{foreach \$items as \$item}{/foreach}\n");

		self::assertStringNotContainsString('trigger_error', $php);
		self::assertStringNotContainsString('array_intersect_key', $php);
		self::assertStringNotContainsString('getReferringTemplate', $php);
		if (InstalledVersionsGuard::latteMajor() === 2) {
			self::assertStringContainsString('UIRuntime::initialize', $php);
		}
	}

	public function testEmptyPrepareMethodRemoved(): void
	{
		$code = <<<'PHP'
			<?php

			final class T
			{

				public function lattePrepare(): void
				{
					if (!$this->getReferringTemplate() || $this->getReferenceType() === "extends") {
						foreach (array_intersect_key(['item' => '48'], $this->params) as $ʟ_v => $ʟ_l) {
							trigger_error("Variable \$$ʟ_v overwritten in foreach on line $ʟ_l");
						}
					}
				}

			}
			PHP;

		$stmts = PHPStanTestCase::getParser()->parseString($code);
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new PrologEliminator(new ShapeFamily(ShapeFamily::LATTE_2, ShapeFamily::FORMS_MACROS)));
		$stmts = $traverser->traverse($stmts);

		$printed = (new Standard())->prettyPrintFile($stmts);

		self::assertStringNotContainsString('function lattePrepare', $printed);
	}

	public function testIteratorTypedAndForeachPrecise(): void
	{
		$php = $this->process(
			"{varType array<int> \$items}\n{foreach \$items as \$i}{\$iterator->counter}{\$i}{/foreach}\n",
		);

		self::assertStringContainsString('$iterator = new \\' . self::cachingIteratorClass() . '($items', $php);
		self::assertStringContainsString('foreach ($items as $i)', $php);
		self::assertStringNotContainsString('ʟ_it', $php);
	}

	public function testPlainForeachUntouched(): void
	{
		$php = $this->process("{varType array<int> \$items}\n{foreach \$items as \$i}{\$i}{/foreach}\n");

		self::assertStringContainsString('foreach ($items as $i)', $php);
		self::assertStringNotContainsString('CachingIterator', $php);
	}

	public function testNoLatteInternalsRemainInControlFlowFixture(): void
	{
		$php = $this->processFixture('control-flow.latte');

		self::assertStringNotContainsString('ʟ_', $php);
	}

	public function testNoLatteInternalsRemainInForeachIteratorFixture(): void
	{
		$php = $this->processFixture('foreach-iterator.latte');

		self::assertStringNotContainsString('ʟ_', $php);
	}

	public function testNoObBufferingRemainsInControlFlowFixture(): void
	{
		$php = $this->processFixture('control-flow.latte');

		self::assertStringNotContainsString('ob_start', $php);
		self::assertStringNotContainsString('ob_get', $php);
		self::assertStringNotContainsString('ob_end', $php);
	}

	public function testNestedForeachEachLevelGetsOwnIterator(): void
	{
		$php = $this->process(
			"{varType array \$groups}\n"
			. "{foreach \$groups as \$group}\n"
			. "{foreach \$group as \$item}{\$iterator->isFirst()}{\$item}{/foreach}\n"
			. "{\$iterator->isLast()}\n"
			. "{/foreach}\n",
		);

		self::assertStringNotContainsString('ʟ_it', $php);
		self::assertSame(2, substr_count($php, 'new \\' . self::cachingIteratorClass() . '('));
		self::assertStringContainsString('$iterator = new \\' . self::cachingIteratorClass() . '($groups);', $php);
		self::assertStringContainsString('$iterator = new \\' . self::cachingIteratorClass() . '($group);', $php);
		self::assertStringContainsString('foreach ($groups as $group)', $php);
		self::assertStringContainsString('foreach ($group as $item)', $php);
		self::assertStringNotContainsString('getParent', $php);
	}

	public function testSwitchTempRenamedNotRemoved(): void
	{
		$php = $this->process(
			"{varType int \$level}\n{switch \$level}{case 1}one{case 2, 3}two{default}other{/switch}\n",
		);

		self::assertStringNotContainsString('ʟ_switch', $php);
		self::assertStringContainsString('in_array(', $php);
	}

	public function testTryRollbackShellUnwrappedAndExceptionRenamed(): void
	{
		$php = $this->process(
			"{varType bool \$flag}\n{try}risky{if \$flag}{rollback}{/if}{else}caught{/try}\n",
		);

		self::assertStringNotContainsString('ʟ_', $php);
		self::assertStringNotContainsString('ob_start', $php);
		self::assertStringNotContainsString('ob_get', $php);
		self::assertStringContainsString('catch (\\Throwable $latteException)', $php);
		self::assertStringContainsString('try {', $php);
	}

	public function testIfchangedKeepsComparedExpressionAnalyzed(): void
	{
		$php = $this->process("{varType int \$level}\n{ifchanged \$level}changed to {\$level}{/ifchanged}\n");

		self::assertStringNotContainsString('ʟ_loc', $php);
		self::assertStringContainsString('if (true)', $php);
		self::assertStringContainsString('$level', $php);
	}

	public function testCaptureFormIfBecomesPlainIf(): void
	{
		$php = $this->process("{varType bool \$showCaptured}\n{if}captured{/if \$showCaptured}\n");

		self::assertStringNotContainsString('ʟ_ifA', $php);
		self::assertStringNotContainsString('ob_start', $php);
		self::assertStringContainsString('if ($showCaptured)', $php);
		self::assertStringContainsString('captured', $php);
	}

	public function testCaptureDefinesStringVar(): void
	{
		$php = $this->process("{varType string \$name}\n{capture \$v}<b>{\$name}</b>{/capture}\n{\$v|upper}\n");

		self::assertStringContainsString('$v = \\OriPhpstan\\Nette\\Latte\\Runtime\\Helpers::capturedString()', $php);
		self::assertStringNotContainsString('ob_start', $php);
		self::assertStringContainsString('echo $name', $php);
	}

	public function testNClassExpressionsAnalyzed(): void
	{
		$php = $this->process("{varType bool \$on}\n<div n:class=\"\$on ? active, static\">x</div>\n");

		self::assertStringNotContainsString('array_filter', $php);
		self::assertStringNotContainsString('array_unique', $php);
		self::assertStringContainsString('Helpers::classes([', $php);
		self::assertStringContainsString('$on ?', $php);
		self::assertStringNotContainsString('ʟ_tmp', $php);
	}

	public function testNClassAlwaysTruthyTernaryUsesHelperWithNoArrayFilterShell(): void
	{
		// Regression (user report): both ternary branches are non-empty string literals, so
		// array_filter can never remove anything - phpstan-strict-rules' arrayFilter.strict and
		// core's arrayFilter.same both flag the vendor array_filter() shell as pointless. The
		// eliminator must route n:class through Helpers::classes() instead of array_filter/implode
		// so there is no array_filter call left for those rules to flag.
		$php = $this->process(
			"{varType bool \$cond}\n<div n:class=\"\$cond ? 'form-group has-error' : 'form-group'\">x</div>\n",
		);

		self::assertStringNotContainsString('array_filter', $php);
		self::assertStringNotContainsString('array_unique', $php);
		self::assertStringContainsString(
			"Helpers::classes([\$cond ? 'form-group has-error' : 'form-group'])",
			$php,
		);
	}

	public function testSpacelessShellDropped(): void
	{
		$php = $this->process("{varType string \$x}\n{spaceless}<div>{\$x}</div>{/spaceless}\n");

		self::assertStringNotContainsString('ob_start', $php);
		self::assertStringNotContainsString('ob_end_flush', $php);
		self::assertStringContainsString('echo $x', $php);
	}

	public function testNAttrExpressionsAnalyzed(): void
	{
		$php = $this->process("{varType bool \$cond}\n<div n:attr=\"data-x => \$cond ? 1 : null\"></div>\n");

		self::assertStringContainsString(
			InstalledVersionsGuard::latteMajor() === 2
				? '\Latte\Runtime\Filters::htmlAttributes([\'data-x\' => $cond ? 1 : \null])'
				: '\Latte\Essential\Nodes\NAttrNode::attrs([\'data-x\' => $cond ? 1 : \null], \false)',
			$php,
		);
		self::assertStringNotContainsString('ʟ_', $php);
	}

	public function testNTagExpressionAnalyzed(): void
	{
		$php = $this->process("{varType string \$tagName}\n<div n:tag=\"\$tagName\">x</div>\n");

		self::assertStringNotContainsString('ʟ_', $php);
		self::assertStringContainsString('$tagName', $php);
		if (InstalledVersionsGuard::latteMajor() === 2) {
			self::assertStringContainsString('checkTagSwitch(\'div\', $latteTag0)', $php);
		} else {
			self::assertStringContainsString(
				'$latteTagName = \Latte\Runtime\HtmlHelpers::validateTagChange($tagName, \'div\');',
				$php,
			);
			self::assertStringContainsString("echo '<', \$latteTagName;", $php);
		}
	}

	public function testNIfcontentReducedToBody(): void
	{
		$php = $this->process("{varType bool \$cond}\n<p n:ifcontent>{if \$cond}x{/if}</p>\n");

		self::assertStringNotContainsString('ʟ_ifc', $php);
		self::assertStringNotContainsString('ob_start', $php);
		self::assertStringNotContainsString('ob_get', $php);
		self::assertStringContainsString('if ($cond)', $php);
	}

	public function testNNonceShellDropped(): void
	{
		$php = $this->process("<script n:nonce>x=1;</script>\n");

		self::assertStringNotContainsString('uiNonce', $php);
	}

	public function testTranslateDynamicContentShellDropped(): void
	{
		$php = $this->process("{varType string \$name}\n{translate}Hello {\$name}{/translate}\n");

		self::assertStringNotContainsString('ob_start', $php);
		self::assertStringNotContainsString('filterContent', $php);
		self::assertStringNotContainsString('ʟ_', $php);
		self::assertStringContainsString(
			'Helpers::translate(\\OriPhpstan\\Nette\\Latte\\Runtime\\Helpers::capturedString())',
			$php,
		);
		self::assertStringContainsString('$name', $php);
	}

	/**
	 * @group latte2
	 */
	public function testFormMacroBindsTypedFormVariable(): void
	{
		$php = $this->process("{form login}{input user}{/form}\n");

		self::assertMatchesRegularExpression(
			'~\$form = \\\\OriPhpstan\\\\Nette\\\\Latte\\\\Runtime\\\\Helpers::form\(([\'"])login\1\)~',
			$php,
		);
		self::assertMatchesRegularExpression('~Helpers::formField\(([\'"])user\1\)~', $php);
		self::assertStringNotContainsString('formsStack', $php);
	}

	public function testControlShellReducedButArgsAnalyzed(): void
	{
		$php = $this->process("{varType int \$id}\n{control card \$id, mode => 'x'}\n");

		self::assertStringContainsString('Helpers::component', $php);
		self::assertStringContainsString('$id', $php);
		self::assertStringNotContainsString('uiControl', $php);
	}

	public function testBlockRenderedInPlaceAndCallable(): void
	{
		$php = $this->process("{varType string \$t}\n{block head}{\$t}{/block}\n{include #head}\n");

		self::assertStringContainsString('function blockHead', $php);
		// $t is a required, header-typed param on blockHead() (via DeclarationInjector) - a
		// zero-arg call would be a PHPStan argument-count error, so the header var is threaded by
		// name from the caller's own (identically-named) param instead. Both call sites collapse to
		// the same shape since {include #head} passes no extra positional args of its own.
		self::assertSame(2, substr_count($php, '$this->blockHead($t);'));
		self::assertStringNotContainsString('renderBlock(', $php);
	}

	/**
	 * @group latte2
	 */
	public function testFormContainerPushAndPopDropFormsStack(): void
	{
		$php = $this->process("{form f}{formContainer c}{input z}{/formContainer}{/form}\n");

		self::assertMatchesRegularExpression('~Helpers::formContainer\(([\'"])c\1\)~', $php);
		self::assertMatchesRegularExpression('~Helpers::formField\(([\'"])z\1\)~', $php);
		self::assertStringNotContainsString('formsStack', $php);
		self::assertStringNotContainsString('array_pop', $php);
	}

	/**
	 * @group latte2
	 */
	public function testNNameInputUsesFormFieldAndDropsLatteTemp(): void
	{
		$php = $this->process("{form f}<input n:name=\"x\">{/form}\n");

		self::assertMatchesRegularExpression('~Helpers::formField\(([\'"])x\1\)~', $php);
		self::assertStringNotContainsString('ʟ_', $php);
	}

	public function testPlinkUsesUiLinkHelper(): void
	{
		$php = $this->process("<a href=\"{plink Foo:bar}\">x</a>\n");

		self::assertStringContainsString('Helpers::uiLink(', $php);
		self::assertStringContainsString('Foo:bar', $php);
		self::assertStringNotContainsString('uiPresenter->link', $php);
		self::assertStringNotContainsString('escapeHtmlAttr', $php);
	}

	public function testSnippetBodyPreservedWithoutDriverShell(): void
	{
		$php = $this->process("{snippet s}<p>hi</p>{/snippet}\n");

		self::assertStringNotContainsString('snippetDriver->enter', $php);
		self::assertStringNotContainsString('snippetDriver->leave', $php);
		self::assertStringNotContainsString('snippetDriver->getHtmlId', $php);
		self::assertStringContainsString('Helpers::snippetId(\'s\')', $php);
		self::assertStringContainsString('<p>hi</p>', $php);
		self::assertStringContainsString('$this->blockS();', $php);
	}

	public function testSnippetIdRewrittenForDynamicName(): void
	{
		$php = $this->process("{varType string \$name}\n{snippet \$name}<p>hi</p>{/snippet}\n");

		self::assertStringNotContainsString('snippetDriver->getHtmlId', $php);
		self::assertStringContainsString('Helpers::snippetId($ʟ_nm = $name)', $php);
	}

	/**
	 * @group latte2
	 */
	public function testObjectFormUsesFormObjectHelper(): void
	{
		$php = $this->process("{varType \\Nette\\Forms\\Form \$myForm}\n{form \$myForm}{input x}{/form}\n");

		self::assertStringContainsString('Helpers::formObject(', $php);
		self::assertStringContainsString('$myForm', $php);
		self::assertStringNotContainsString('is_object', $php);
		self::assertStringNotContainsString('uiControl', $php);
	}

	public function testIfCurrentWithDestinationRoutesThroughHelperAndKeepsArgsAnalyzed(): void
	{
		InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		$php = $this->process(
			"{varType string \$dest}\n{varType string \$label}\n{varType string \$extra}\n"
			. "{ifCurrent \$dest, mode => \$extra}\n\t{\$label}\n{/ifCurrent}\n",
		);

		self::assertStringContainsString('Helpers::uiIsLinkCurrent($dest)', $php);
		self::assertStringNotContainsString('->uiPresenter->isLinkCurrent(', $php);
		self::assertStringContainsString('$label', $php);
		self::assertStringContainsString('$extra', $php);
	}

	public function testIfCurrentWithoutDestinationRoutesThroughHelperWithNull(): void
	{
		InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		$php = $this->process("{varType string \$label}\n{ifCurrent}\n\t{\$label}\n{/ifCurrent}\n");

		self::assertStringContainsString('Helpers::uiIsLinkCurrent(null)', $php);
		self::assertStringNotContainsString('getLastCreatedRequestFlag', $php);
		self::assertStringContainsString('$label', $php);
	}

	public function testEmbedFileFormRoutesThroughHelperAndDropsLayerPlumbing(): void
	{
		$php = $this->process(
			"{varType string \$mode}\n"
			. "{embed file 'blocks-snippets.latte', mode => \$mode}\n"
			. "\t{block content}override {\$mode}{/block}\n"
			. "{/embed}\n",
		);

		self::assertStringContainsString('Helpers::embedTemplate(', $php);
		self::assertStringContainsString('blocks-snippets.latte', $php);
		self::assertStringContainsString('function blockContent', $php);
		self::assertStringContainsString('override ', $php);
		self::assertStringContainsString('$mode', $php);
		self::assertStringNotContainsString('enterBlockLayer', $php);
		self::assertStringNotContainsString('leaveBlockLayer', $php);
		self::assertStringNotContainsString('createTemplate', $php);
	}

	public function testEmbedBlockFormDropsLayerPlumbingKeepsDirectCall(): void
	{
		$php = $this->process("{block sub}x{/block}\n{embed block sub}\n{/embed}\n");

		self::assertStringContainsString('$this->blockSub();', $php);
		self::assertStringNotContainsString('enterBlockLayer', $php);
		self::assertStringNotContainsString('copyBlockLayer', $php);
		self::assertStringNotContainsString('leaveBlockLayer', $php);
		self::assertStringNotContainsString('renderBlock', $php);
	}

	public function testNoLatteInternalsRemainInIfCurrentFixture(): void
	{
		InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		$php = $this->processFixture('ifcurrent.latte');

		self::assertStringNotContainsString('ʟ_', $php);
	}

	public function testNoLatteInternalsRemainInEmbedFixture(): void
	{
		$php = $this->processFixture('embed.latte');

		self::assertStringNotContainsString('ʟ_', $php);
		self::assertStringNotContainsString('enterBlockLayer', $php);
		self::assertStringNotContainsString('copyBlockLayer', $php);
		self::assertStringNotContainsString('leaveBlockLayer', $php);
		self::assertStringNotContainsString('createTemplate', $php);
	}

	public function testFilterRewrittenToRealCallable(): void
	{
		$php = $this->process("{varType string \$s}\n{\$s|truncate:10}\n");

		self::assertStringContainsString('\\' . self::filtersClass() . '::truncate($s, 10)', $php);
		self::assertStringNotContainsString('this->filters', $php);
	}

	public function testContentAwareFilterGetsFilterInfo(): void
	{
		$php = $this->process("{varType string \$s}\n{\$s|trim}\n");

		self::assertStringContainsString(
			'Filters::trim(\\OriPhpstan\\Nette\\Latte\\Runtime\\Helpers::filterInfo(), $s)',
			$php,
		);
	}

	public function testUnknownFilterDiagnosedArgsKept(): void
	{
		$php = $this->process("{varType string \$s}\n{\$s|whatsappFormat}\n");

		self::assertStringContainsString('Helpers::unknownFilter($s)', $php);
	}

	public function testUnknownFilterDiagnosticIsCollected(): void
	{
		$latte = "{varType string \$greeting}\n{\$greeting|notARealLatteFilter}\n";
		$compiled = TestAdapter::create()->compile($latte, 'LatteTpl_test_diag', 'fixtures/diag.latte');
		$pipeline = PipelineFactory::create();
		$pipeline->dump($compiled->getResult(), $compiled->getFacts()->getDeclarations());

		$diagnostics = $pipeline->getDiagnostics();

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.unknownFilter', $diagnostics[0]->getIdentifier());
	}

	public function testUnknownFilterDiagnosticIsCollectedOnEachParseOfByteIdenticalSource(): void
	{
		// Regression: PipelineFactory (mirroring the production latteAnalysisPipeline service) is
		// wired to currentPhpVersionSimpleParser, which is UNCACHED. latteInnerAnalysisParser's
		// CachedParser would instead return the same, already-rewritten node objects for a repeat
		// parse of byte-identical generated PHP - silently dropping this diagnostic on the second
		// call and sharing mutable line attributes between the two ASTs.
		$latte = "{varType string \$greeting}\n{\$greeting|notARealLatteFilterEitherXyz}\n";
		$compiledTemplate = TestAdapter::create()->compile($latte, 'LatteTpl_test_diag_repeat', 'fixtures/diag.latte');
		$compiled = $compiledTemplate->getResult();
		$declarations = $compiledTemplate->getFacts()->getDeclarations();

		$pipelineA = PipelineFactory::create();
		$stmtsFromA = $pipelineA->process($compiled, $declarations);
		$diagnosticsFromA = $pipelineA->getDiagnostics();

		$pipelineB = PipelineFactory::create();
		$stmtsFromB = $pipelineB->process($compiled, $declarations);
		$diagnosticsFromB = $pipelineB->getDiagnostics();

		self::assertCount(1, $diagnosticsFromA);
		self::assertSame('orisaiNette.latte.unknownFilter', $diagnosticsFromA[0]->getIdentifier());
		self::assertCount(1, $diagnosticsFromB);
		self::assertSame('orisaiNette.latte.unknownFilter', $diagnosticsFromB[0]->getIdentifier());

		self::assertNotSame(
			$stmtsFromA[0],
			$stmtsFromB[0],
			'each parse of identical source must yield independent AST node objects',
		);
	}

	public function testCaseInsensitiveFilterResolvesToCanonicalCasing(): void
	{
		$php = $this->process("{varType string \$s}\n{\$s|datastream}\n");

		self::assertStringContainsString('\\' . self::filtersClass() . '::dataStream($s)', $php);
		self::assertStringNotContainsString('this->filters', $php);
	}

	public function testFunctionCallRewrittenToRealCallable(): void
	{
		$php = $this->process("{varType int \$v}\n{=clamp(\$v, 0, 10)}\n");

		self::assertStringContainsString('\\' . self::filtersClass() . '::clamp($v, 0, 10)', $php);
		self::assertStringNotContainsString('global->fn', $php);
	}

	public function testNoLatteInternalsRemainInOutputFiltersFixture(): void
	{
		$php = $this->processFixture('output-filters.latte');

		self::assertStringNotContainsString('ʟ_', $php);
		self::assertStringNotContainsString('this->filters', $php);
		self::assertStringNotContainsString('global->fn', $php);
	}

	private function process(string $latte): string
	{
		$compiled = TestAdapter::create()->compile($latte, 'LatteTpl_test', 'fixtures/test.latte');

		return PipelineFactory::create()->dump(
			$compiled->getResult(),
			$compiled->getFacts()->getDeclarations(),
		);
	}

	private function processFixture(string $name): string
	{
		return $this->process(FileSystem::read(__DIR__ . '/../Fixtures/' . $name));
	}

	private static function cachingIteratorClass(): string
	{
		return FamilyPatterns::for(TestAdapter::factory()->family(), IteratorEliminator::class)
			->name(IteratorEliminator::ROLE_CACHING_ITERATOR);
	}

	private static function filtersClass(): string
	{
		return InstalledVersionsGuard::latteMajor() === 2 ? 'Latte\Runtime\Filters' : 'Latte\Essential\Filters';
	}

}
