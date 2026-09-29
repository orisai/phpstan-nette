<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\RuntimeParity;

use DateTimeImmutable;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use Latte\Runtime\Filters;
use Latte\Sandbox\SecurityPolicy;
use Nette\Utils\FileSystem;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function rtrim;

// {translate}/{_} are not probed: they need a registered translator and Presenter/Control
// binding this bare StringLoader engine does not provide.
final class RuntimeParityTest extends BaseTestCase
{

	public function testVarThenDefaultDoesNotOverwriteAndDefaultDefinesNewVariable(): void
	{
		// Proves the pipeline's {default} -> `??=` rewrite (DeclarationInjector::rewriteMethodBody)
		// for the ordinary case: {var $x=1} then {default $x=2} keeps 1; {default $y=3} on a
		// never-assigned $y defines it.
		self::assertSame('1,3', $this->render('var-default.latte', []));
	}

	public function testDefaultDoesNotOverwriteProvidedParam(): void
	{
		// Runtime proof for the pipeline's EXTR_SKIP -> `??=` modelling of {default} against a
		// template parameter actually supplied by the caller.
		self::assertSame('given', $this->render('default-provided-param.latte', ['p' => 'given']));
	}

	public function testDefaultDoesNotOverwriteExistingNullLocal(): void
	{
		// CONTRADICTION, documented not fixed: extract(..., EXTR_SKIP) skips a variable that already
		// EXISTS in the symbol table, even when its value is null. A naive `$x ??= 2` model would overwrite
		// null (isset($x) is false for null), predicting 2 here. Real Latte leaves $x === null:
		// the pipeline's `??=` rewrite is unsound for a local set to null before {default} runs.
		self::assertSame('NULL', $this->render('default-null-local.latte', []));
	}

	public function testDefaultDoesNotOverwriteExplicitlyPassedNullParam(): void
	{
		// Same EXTR_SKIP-vs-`??=` divergence as above, reached through a template parameter
		// explicitly passed as null instead of a {var}-assigned local: {default} still leaves it
		// null, it does not fall back to the default value.
		self::assertSame('NULL', $this->render('default-null-param.latte', ['p' => null]));
	}

	/**
	 * @group latte2
	 */
	public function testForeachIteratorIsCachingIteratorWithOneIndexedCounter(): void
	{
		// Runtime proof for the pipeline's typed local ($iterator: CachingIterator, via
		// IteratorEliminator + the latte-runtime.stub @property-read declarations): the real
		// $iterator inside {foreach} is Latte\Runtime\CachingIterator, ->counter is 1-indexed,
		// ->counter0 is 0-indexed.
		self::assertSame(
			'Latte\Runtime\CachingIterator|1|0|Latte\Runtime\CachingIterator|2|1|Latte\Runtime\CachingIterator|3|2|',
			$this->render('iterator.latte', ['items' => [7, 8, 9]]),
		);
	}

	public function testParametersDeclaredVarIsVisibleInsideBlock(): void
	{
		// Runtime rule this probe pins (dumped via Engine::compile('probe'), confirmed against
		// vendor 2.11.7): {parameters} only replaces main()'s/prepare()'s extraction prolog
		// (Compiler::finalize(): `$extractParams = $this->paramsExtraction ?? 'extract($this->params);'`,
		// consumed only by the 'main'/'prepare' addMethod() calls) - it never touches $this->params
		// itself. A top-level {block} (BlockMacros::extractMethod, non-embed branch) always compiles
		// to `extract($this->params); extract($ʟ_args); unset($ʟ_args);`, and its call site in
		// main() is `$this->renderBlock('b', get_defined_vars())` (BlockMacros::macroBlock) - which
		// already includes $p, since the {parameters} extraction ran earlier in main(). Both paths
		// converge: $p reaches the block whether invoked via a normal main() render or via a
		// single-block Template::render('b') call (Template::doRender() also passes $this->params
		// straight into renderBlock()).
		self::assertSame('x', $this->render('parameters-block.latte', ['p' => 'x']));
	}

	public function testDoDefinesRuntimeVariable(): void
	{
		// Proves {do $n = 5} is a plain native assignment statement at runtime, matching the
		// pipeline's untouched pass-through of {do}/{php} bodies.
		self::assertSame('int|5', $this->render('do-defines-variable.latte', []));
	}

	/**
	 * @group latte2
	 */
	public function testPhpDefinesRuntimeVariable(): void
	{
		self::assertSame('int|7', $this->render('php-defines-variable.latte', []));
	}

	public function testCaptureOfNonEmptyContentYieldsHtmlObjectNotString(): void
	{
		// The pipeline modelled {capture $c}...{/capture}
		// as `Helpers::capturedString(): string` (CaptureEliminator/FilterRewriter), but vendor
		// CoreMacros::macroCaptureEnd() compiles a non-empty capture in HTML/XHTML content (the
		// Engine's default content type) to
		// `ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean()` - a non-empty buffer
		// yields a Latte\Runtime\Html object, not a string. get_class()/get_debug_type() confirm
		// it at runtime, so Helpers::capturedString()'s declared return type is
		// `\Latte\Runtime\Html|string`.
		self::assertSame(
			'Latte\Runtime\Html|Latte\Runtime\Html|[hi]',
			$this->render('capture-html.latte', []),
		);
	}

	public function testCaptureOfEmptyContentYieldsPlainString(): void
	{
		// The other branch of the same ternary: an EMPTY captured buffer takes the ob_get_clean()
		// path directly and yields a plain (empty) string - confirming the union
		// `\Latte\Runtime\Html|string` is exact, not just Html.
		self::assertSame('string', $this->render('capture-empty.latte', []));
	}

	public function testCaptureInNonHtmlContentAlwaysYieldsString(): void
	{
		// macroCaptureEnd()'s ternary only applies when the surrounding context is HTML/XHTML;
		// other content types (text, js, css, ...) always compile to a bare ob_get_clean(), so a
		// captured variable there stays a plain string even for non-empty content.
		self::assertSame('string', $this->render('capture-text-context.latte', [], Engine::CONTENT_TEXT));
	}

	public function testCapturedHtmlObjectSurvivesUpperFilter(): void
	{
		// Confirms the item-5 fix does not ripple downstream: Latte\Runtime\Filters::upper($s)
		// takes an untyped $s and does (string) $s internally, so a captured Html object (via its
		// __toString()) is filtered exactly like a plain string would be.
		self::assertSame('HI', $this->render('capture-upper-filter.latte', []));
	}

	public function testIfConditionArrayTruthinessMatchesPhpCoercion(): void
	{
		// Runtime proof for WHY the project's full-strictness `if.condNotBoolean` rule is a true
		// positive on `{if $array}`, not a pipeline false-flag: {if} compiles to a bare
		// `if (EXPR)` (CoreMacros::macroIf, no bool cast), so its truthiness is exactly PHP's
		// native array coercion - empty array is falsy, non-empty array is truthy.
		self::assertSame('no', $this->render('truthiness.latte', ['arr' => []]));
		self::assertSame('yes', $this->render('truthiness.latte', ['arr' => [1]]));
	}

	/**
	 * @group latte2
	 */
	public function testDateFilterMatchesVendorNullableStringSignature(): void
	{
		// @phpstan-ignore classConstant.internalClass (Filters is @internal, same exemption as Postprocess/FilterTable.php)
		$returnType = (new ReflectionMethod(Filters::class, 'date'))->getReturnType();
		self::assertInstanceOf(ReflectionNamedType::class, $returnType);
		self::assertSame('string', $returnType->getName());
		self::assertTrue(
			$returnType->allowsNull(),
			'Filters::date declares ?string - FilterTable resolves |date straight to this method, '
			. 'so the rewrite target IS the runtime: |date must be modelled as string|null.',
		);

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', '2020-01-02');
		self::assertInstanceOf(DateTimeImmutable::class, $date);

		self::assertSame('null|', $this->render('filter-date.latte', ['d' => null]));
		self::assertSame('string|2.1.2020', $this->render('filter-date.latte', ['d' => $date]));
	}

	/**
	 * @group latte2
	 */
	public function testNumberFilterReturnsString(): void
	{
		// |number resolves to native number_format(); PHP 7.4's ReflectionFunction exposes no
		// return-type info for internal functions (unlike Filters::date/batch below), so this
		// probe leans on the runtime observation alone.
		self::assertSame('string|1,235', $this->render('filter-number.latte', ['n' => 1234.5]));
	}

	/**
	 * @group latte2
	 */
	public function testBatchFilterMatchesVendorGeneratorSignature(): void
	{
		// @phpstan-ignore classConstant.internalClass (Filters is @internal, same exemption as Postprocess/FilterTable.php)
		$returnType = (new ReflectionMethod(Filters::class, 'batch'))->getReturnType();
		self::assertInstanceOf(ReflectionNamedType::class, $returnType);
		self::assertSame('Generator', $returnType->getName());
		self::assertFalse($returnType->allowsNull());

		self::assertSame('Generator', $this->render('filter-batch.latte', ['list' => [1, 2, 3, 4]]));
	}

	public function testIncludeFilePassesParamsNotLocals(): void
	{
		// Runtime proof for the ContextResolver include-scoping model: CoreMacros::macroInclude
		// compiles a file include's params to `%node.array? + $this->params` - the includer's own
		// TEMPLATE PARAMS, never `get_defined_vars()`. A {var} local assigned in the includer's body
		// never updates $this->params, so it stays invisible to the target: $local is undefined in
		// the partial while $shared (an actual render() param) is visible.
		self::assertSame(
			'no|yes',
			$this->renderSet(['main' => 'scope-main.latte', 'part' => 'scope-part.latte'], 'main', ['shared' => 'yes']),
		);
	}

	public function testIncludeExplicitArgOverridesParam(): void
	{
		// Same `%node.array? + $this->params` PHP array union as above: the include tag's own
		// explicit args sit on the LEFT of `+`, so a same-named key from `with (...)` args wins over
		// the includer's own template param of that name.
		self::assertSame(
			'no|over',
			$this->renderSet(
				['main' => 'scope-override-main.latte', 'part' => 'scope-part.latte'],
				'main',
				['shared' => 'yes'],
			),
		);
	}

	public function testBlockIncludeSeesCallerLocals(): void
	{
		// {include #block} takes a wholly different vendor code path than a file include:
		// BlockMacros::macroInclude compiles it to `$this->renderBlock($name, get_defined_vars() + ...)`
		// - the caller's OWN runtime locals at the include site, not $this->params. $loc is a plain
		// {var} local, invisible to a file include (previous probe) but visible here.
		self::assertSame('5', $this->renderSet(['main' => 'scope-block.latte'], 'main', []));
	}

	public function testSandboxSeesOnlyExplicitArgs(): void
	{
		// {sandbox} compiles to `$this->createTemplate(%word, %node.array, "sandbox")`
		// (CoreMacros::macroSandbox) - no `+ $this->params` term at all, stricter than a plain
		// {include}: the sandboxed target sees ONLY the tag's own explicit args, not even the
		// includer's template params. Sandbox mode requires an engine Policy (vendor
		// Engine::createTemplate() throws LogicException without one); renderSet() sets
		// SecurityPolicy::createSafePolicy() unconditionally, a no-op for the non-sandboxed probes
		// since Compiler::setPolicy() only consults it when the engine is actually sandboxed.
		self::assertSame(
			'no|seen',
			$this->renderSet(
				['main' => 'scope-sandbox-main.latte', 'target' => 'scope-sandbox-target.latte'],
				'main',
				['param' => 'hidden'],
			),
		);
	}

	public function testLayoutSeesChildFinishedScope(): void
	{
		// {layout}/{extends}: Template::doRender() runs the child's ENTIRE main() first
		// ($this->params = $this->main()), discards its buffered output, then hands that returned
		// get_defined_vars() - the child's finished scope, {var} locals included - to the layout as
		// its own params. The opposite of {include}: the layout sees MORE than a plain include would.
		self::assertSame(
			'x',
			$this->renderSet(['child' => 'scope-child.latte', 'lay' => 'scope-layout.latte'], 'child', []),
		);
	}

	public function testImportedBlockRunsInImporterScope(): void
	{
		// Runtime rule this probe pins: BlockMacros::finalize()'s get_defined_vars() placeholder
		// substitution only fires when the target block is in the SAME file's own compile-time block
		// registry (confirmed working in the previous probe); a block reached only via {import} isn't
		// in that registry, so the compiled call site passes a literal `[]` for $ʟ_args. The analysis
		// model matches: DeclaredVarsResolver::forFile() surfaces only formally-declared vars
		// ({parameters}/{varType}/{templateType} properties) and never reads {var}/{default} body
		// declarations for ANY edge, so an importer's {var} local, declared after
		// the {import}, never reaches an {include #imported} block.
		self::assertSame(
			'MISSING',
			$this->renderSet(
				['importer' => 'scope-import-main.latte', 'lib' => 'scope-import-lib.latte'],
				'importer',
				[],
			),
		);

		// What DOES reach the imported block: BlockMacros::macroImport creates the imported template
		// with `$this->params` - the importer's OWN top-level render() params - so an {import}ed
		// block behaves exactly like a plain file {include}: params flow, runtime {var} locals don't.
		self::assertSame(
			'param-value',
			$this->renderSet(
				['importer' => 'scope-import-param-main.latte', 'lib' => 'scope-import-lib.latte'],
				'importer',
				['fromImporter' => 'param-value'],
			),
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function render(string $fixture, array $params, ?string $contentType = null): string
	{
		$engine = new Engine();
		if ($contentType !== null) {
			$engine->setContentType($contentType);
		}

		$engine->setLoader(new StringLoader(['probe' => FileSystem::read(__DIR__ . '/Fixtures/' . $fixture)]));

		return rtrim($engine->renderToString('probe', $params), "\n");
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
