<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\RuntimeParity;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Latte\RuntimeException;
use Latte\Sandbox\SecurityPolicy;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function error_reporting;
use function rtrim;
use const E_ALL;
use const E_DEPRECATED;
use const E_NOTICE;
use const E_STRICT;

// Grounds the "declaration consistency" design: paired execute-and-compare probes for how
// {import}/{include file}/{include block} propagate `{var}` locals, template params and
// explicit args across a two-file A->B edge, verified against real Latte 2.11.7 (VERSION_ID
// 21107). Findings feed docs/phpstan-latte.md's C6 table.
final class CrossFileScopeParityTest extends BaseTestCase
{

	public function testImportBothTopLevelVarsBlockSeesNeitherAndLibNeverRuns(): void
	{
		// BlockMacros::macroImport compiles {import 'lib'} to
		// `$this->createTemplate('lib', $this->params, "import")->render()`. Template::render()
		// calls prepare() then, only `if (!$this->doRender($block))`, main(). Template::doRender()'s
		// `referenceType === 'import'` branch never returns false, so main() - the method holding
		// every top-level {var} assignment AND ordinary literal output - never runs for an imported
		// template. MARKER-B-RAN (plain text right after lib's {var $x='B-top'}) proves this
		// directly: if lib's main() ran, its literal output would show up in the entry's rendered
		// string; it never does, from any probe in this file. So neither lib's own {var $x='B-top'}
		// nor the importer's post-import {var $x='A-top'} reaches the imported block: both are
		// {var} locals, and only formally declared vars (never {var} bodies) feed the analysis
		// model's cross-file scope in the first place.
		self::assertSame('UNDEF', $this->renderSet(
			['lib' => 'cfs-p1-lib.latte', 'main' => 'cfs-p1-main.latte'],
			'main',
			[],
		));
	}

	public function testImportBlockSeesImporterTemplateParam(): void
	{
		// $this->params passed into createTemplate() is the importer's OWN constructor
		// params (render()'s $params argument) - untouched by anything main() does. The imported
		// block's compiled prologue (BlockMacros::extractMethod) always starts with
		// `extract($this->params)` on the block's OWNING template object (lib, bound via the
		// closure copied into the importer's block table by Template::createTemplate()'s
		// cross-registration) before `extract($ʟ_args)`. So a template param the importer itself
		// was called with reaches the block - matching the model's "importer's template params
		// only" rule.
		self::assertSame('A-param', $this->renderSet(
			['lib' => 'cfs-p1-lib.latte', 'main' => 'cfs-p2-main.latte'],
			'main',
			['x' => 'A-param'],
		));
	}

	public function testImportParamWinsOverLaterLocalOfSameName(): void
	{
		// Same mechanism as the previous probe: a {var} reassignment after the {import} only
		// changes a PHP local inside the importer's own main(), never $this->params. The block's
		// extract($this->params) still reads the original constructor param.
		self::assertSame('A-param', $this->renderSet(
			['lib' => 'cfs-p1-lib.latte', 'main' => 'cfs-p3-main.latte'],
			'main',
			['x' => 'A-param'],
		));
	}

	public function testImportConditionallyDefinedTopLevelVarStillNeverRuns(): void
	{
		// Same non-execution proof as the first probe, with lib's {var} wrapped in
		// {if true}: main() never runs regardless of what's inside it, so whether the assignment is
		// unconditional or condition-guarded makes no difference - MARKER-B-RAN is absent here too.
		self::assertSame('UNDEF', $this->renderSet(
			['lib' => 'cfs-p4-lib.latte', 'main' => 'cfs-p4-main.latte'],
			'main',
			[],
		));
	}

	public function testImportExplicitIncludeArgWinsOverParamAndLocal(): void
	{
		// BlockMacros::macroInclude's block-form compiles the include's own args to
		// `%node.array? + $key` and extractMethod runs `extract($ʟ_args)` AFTER
		// `extract($this->params)` - plain PHP extract() overwrites same-named keys by default, so
		// the include site's own explicit arg beats both the importer's template param and any
		// {var} local.
		self::assertSame('explicit-arg', $this->renderSet(
			['lib' => 'cfs-p1-lib.latte', 'main' => 'cfs-p5-main.latte'],
			'main',
			['x' => 'A-param'],
		));
	}

	public function testFileIncludeSameFileBlockSeesIncluderLocals(): void
	{
		// {include #b} compiled from WITHIN b's own file resolves its placeholder at
		// BlockMacros::finalize() time against that file's own compile-time block registry: block
		// b IS registered there (defined in the same file), so the placeholder becomes
		// 'get_defined_vars()' instead of '[]' - the caller's (b's file's own main()) runtime locals
		// at the include site flow straight into the block, no extract($this->params) detour
		// needed. b's own {var $x = 'B-top'} is exactly such a local.
		self::assertSame('B-top', $this->renderSet(
			['b' => 'cfs-p6-b.latte', 'a' => 'cfs-p6-a.latte'],
			'a',
			[],
		));
	}

	public function testFileIncludeDoesNotLeakIncluderLocalsThroughTwoHops(): void
	{
		// CoreMacros::macroInclude's file-form compiles to
		// `$this->createTemplate('b', %args + $this->params, "include")->renderToContentType(...)`
		// - only a's TEMPLATE PARAMS (not a's {var} locals) become b's own $this->params, and b
		// never declares $x itself. b's internal {include #b} (same-file block, previous probe)
		// then extracts b's own (still x-less) get_defined_vars(), so a's {var $x='A-top'} never
		// reaches the block even through this two-hop chain.
		self::assertSame('UNDEF', $this->renderSet(
			['b' => 'cfs-p7-b.latte', 'a' => 'cfs-p7-a.latte'],
			'a',
			[],
		));
	}

	public function testFileIncludeExplicitArgCascadesThroughSameFileBlock(): void
	{
		// The `%args + $this->params` union from the previous probe DOES include the include
		// site's own explicit args (left operand, wins over $this->params on key collision). b's
		// main() prologue is a default `extract($this->params);` (no {parameters} tag overrides
		// it), so the arg becomes a real local by the time b's own {include #b} runs -
		// get_defined_vars() picks it up like any other local.
		self::assertSame('A-arg', $this->renderSet(
			['b' => 'cfs-p7-b.latte', 'a' => 'cfs-p8-a.latte'],
			'a',
			[],
		));
	}

	public function testFileIncludeNeverJoinsTargetBlocksIntoIncludersTable(): void
	{
		// Template::createTemplate() only merges the referred template's LAYER_TOP blocks into the
		// caller's own $this->blocks when $referenceType is 'extends', 'includeblock', 'import' or
		// 'embed' - plain file 'include' is deliberately absent from that list. So after a's
		// {include file 'b'}, a's own block table still has no 'b' entry, and a's subsequent
		// {include b} throws Latte\RuntimeException at Template::renderBlock() - a runtime
		// confirmation that a plain file include never makes the target's blocks callable from the
		// includer, matching the model (an edge into an unimported file's blocks isn't a resolvable
		// include target).
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot include undefined block 'b'.");

		$this->renderSet(
			['b' => 'cfs-p7-b.latte', 'a' => 'cfs-p9-a.latte'],
			'a',
			[],
		);
	}

	public function testImportedBlockParamDefaultNeverSeesLibTopLevel(): void
	{
		// CoreMacros' compiled param line for a {define b, $x = 'PARAM-DEFAULT'} parameter is
		// `$x = $ʟ_args[0] ?? $ʟ_args['x'] ?? 'PARAM-DEFAULT';` (BlockMacros::macroDefine) - it runs
		// unconditionally, AFTER extractMethod's `extract($this->params)` prologue, and always
		// reassigns $x from this formula regardless of what extract() set. {include b} with no args
		// resolves to an empty $ʟ_args (placeholder '[]', since 'b' isn't in the importer's own
		// compile-time registry - same mechanism as the very first probe), so the param falls all
		// the way through to its own literal default - never to lib's (non-executing) top-level
		// {var $x = 'B-top'}.
		self::assertSame('PARAM-DEFAULT', $this->renderSet(
			['lib' => 'cfs-p10-lib.latte', 'main' => 'cfs-p10-main-default.latte'],
			'main',
			[],
		));
	}

	public function testImportedBlockParamExplicitArgWinsOverDefault(): void
	{
		// Same compiled formula as above, this time with a positional {include b, 'arg'}: $ʟ_args =
		// [0 => 'arg'] (BlockMacros::macroInclude's `%node.array? + $key`), so
		// `$ʟ_args[0] ?? ...` short-circuits to the call's own argument before the literal default
		// is ever consulted.
		self::assertSame('arg', $this->renderSet(
			['lib' => 'cfs-p10-lib.latte', 'main' => 'cfs-p10-main-arg.latte'],
			'main',
			[],
		));
	}

	public function testBlockLocalNeverLeaksOutToCaller(): void
	{
		// A block compiles to its own method on the compiled class (BlockMacros::finalize's
		// addMethod() per block); a {var $y=...} inside it is an ordinary PHP local of that
		// method's own call frame, never merged back into the caller's frame after
		// $this->renderBlock() returns - a block is a genuine function-scope boundary, not a
		// textual splice. $y stays undefined in the caller's main() after {include b} returns.
		self::assertSame('UNDEF', $this->renderSet(
			['main' => 'cfs-p11-main.latte'],
			'main',
			[],
		));
	}

	public function testVarTypeHasZeroRuntimeEffectAtTopLevelAndInBlockBody(): void
	{
		// CoreMacros::macroVarType only calls $node->validate() and returns void - no PHP is ever
		// emitted for the tag, at template top level or inside a {define}/{block} body alike. Adding
		// {varType string $x} in both positions (cfs-p12-b-vartype.latte, mirroring the same-file
		// caller-locals scenario from the earlier probe) must not change one byte of rendered
		// output versus the varType-free fixture: a purely compile-time/analysis-time contract, no
		// runtime binding.
		$without = $this->renderSet(
			['b' => 'cfs-p6-b.latte', 'a' => 'cfs-p6-a.latte'],
			'a',
			[],
		);
		$with = $this->renderSet(
			['b' => 'cfs-p12-b-vartype.latte', 'a' => 'cfs-p12-a-vartype.latte'],
			'a',
			[],
		);
		self::assertSame('B-top', $without);
		self::assertSame($without, $with);
	}

	public function testOwnParamBlockNeverExtractsUnmatchedExtraArg(): void
	{
		// BlockMacros::extractMethod only emits the universal `extract($ʟ_args)` fallback for a
		// block with NO own declared params at all (BlockMacros.php's own `$params ? ... : null`
		// branch selects the positional-only prologue instead the moment a block declares even one
		// param) - a named arg beyond the declared param list is never bound for such a block,
		// unlike the fully param-less case (testImportExplicitIncludeArgWinsOverParamAndLocal). $y
		// (the block's own positional param) still resolves to the call's first positional arg;
		// $extra (an unmatched named arg) stays genuinely undefined and degrades silently to
		// 'UNDEF' via the same `?? 'UNDEF'` guard used throughout this file (bare-read notices are
		// suppressed under this project's error_reporting, confirmed by the probe below).
		self::assertSame('OWN-VAL|UNDEF', $this->renderSet(
			['main' => 'cfs-p13-main.latte'],
			'main',
			[],
		));
	}

	public function testBareUndefinedVariableInImportedBlockSilentlyRendersEmpty(): void
	{
		// Dedicated capture of the risky bare-`{$x}` case the other probes deliberately avoid via
		// `?? 'UNDEF'`: an undefined $x inside a block reached only through {import} compiles to a
		// bare PHP variable read (LR\Filters::escapeHtmlText($x), no isset()/?? guard emitted for a
		// plain `{$x}`). PHP's own undefined-variable diagnostic is E_NOTICE, which a common
		// production `error_reporting` (E_ALL minus E_NOTICE/E_STRICT/E_DEPRECATED, set here
		// explicitly) excludes, so PHP never even invokes a registered error handler for it. The read
		// just yields NULL, which escapeHtmlText()/(string) coerces to ''. This IS the runtime-parity
		// data point: a missing binding degrades completely silently, not even a notice - so a
		// runtime error signal can never be relied on to catch this class of mistake; only a static
		// (PHPStan) check can.
		$previous = error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
		try {
			$rendered = $this->renderSet(
				['lib' => 'cfs-bare-lib.latte', 'main' => 'cfs-bare-main.latte'],
				'main',
				[],
			);
		} finally {
			error_reporting($previous);
		}

		self::assertSame('[]', $rendered);
	}

	/**
	 * @param array<string, string> $templates
	 * @param array<string, mixed> $params
	 */
	private function renderSet(array $templates, string $entry, array $params): string
	{
		$sources = [];
		foreach ($templates as $name => $fixture) {
			$sources[$name] = FileSystem::read(__DIR__ . '/Fixtures/' . $fixture);
		}

		$engine = new Engine();
		$engine->setLoader(new StringLoader($sources));
		$engine->setPolicy(SecurityPolicy::createSafePolicy());

		return rtrim($engine->renderToString($entry, $params), "\n");
	}

}
