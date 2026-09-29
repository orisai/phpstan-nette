<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\AttrShellEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\BlockDispatchEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\CaptureEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\ControlFlowEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\DevTagEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EscapingEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FormsMacroEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\IteratorEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\PrologEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\UiMacroEliminator;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterAccessor;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Parser\Parser;
use function array_merge;

final class AnalysisPipeline
{

	private Parser $phpParser;

	private LatteVersionAdapterAccessor $adapterAccessor;

	private ?CustomsHarvester $harvester;

	private ?FilterTable $filterTable = null;

	private ?FunctionTable $functionTable = null;

	private EdgeAnchorInjector $edgeAnchorInjector;

	private DeclarationInjector $declarationInjector;

	private ?TemplateTypeCustoms $templateTypeCustoms;

	/** @var array<Diagnostic> */
	private array $lastDiagnostics = [];

	/** @var list<array{macro: string, provider: string, line: int}> */
	private array $lastProviderMacroSites = [];

	private ProviderMacroScanner $providerMacroScanner;

	private bool $narrowingEnabled;

	private bool $providerScanEnabled;

	public function __construct(
		Parser $phpParser,
		LatteVersionAdapterAccessor $adapterAccessor,
		TemplateEdgeIndex $edgeIndex,
		LatteUniverse $universe,
		CapturedOverlay $capturedOverlay,
		bool $narrowingEnabled,
		?CustomsHarvester $harvester = null,
		?TemplateTypeCustoms $templateTypeCustoms = null,
		bool $includeIsolation = false,
		bool $providerScanEnabled = true
	)
	{
		$this->phpParser = $phpParser;
		$this->adapterAccessor = $adapterAccessor;
		$this->templateTypeCustoms = $templateTypeCustoms;
		$this->harvester = $harvester;

		$this->edgeAnchorInjector = new EdgeAnchorInjector(
			$edgeIndex,
			$universe,
			$phpParser,
			$includeIsolation,
		);
		$this->declarationInjector = new DeclarationInjector(
			$phpParser,
			$edgeIndex,
			$universe,
			$capturedOverlay,
		);
		$this->providerMacroScanner = new ProviderMacroScanner();
		$this->narrowingEnabled = $narrowingEnabled;
		$this->providerScanEnabled = $providerScanEnabled;
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return array<Stmt>
	 */
	public function process(
		CompileResult $compiled,
		Declarations $declarations,
		array $contexts = [],
		string $relativePath = ''
	): array
	{
		$this->lastDiagnostics = [];
		$this->lastProviderMacroSites = [];
		$phpSource = $compiled->getPhpSource();
		if ($phpSource === null) {
			$this->lastDiagnostics = $compiled->getDiagnostics();

			return [];
		}

		return $this->processParsedStatements(
			$phpSource,
			$declarations,
			$compiled->getDiagnostics(),
			$contexts,
			$relativePath,
		);
	}

	/**
	 * @param list<TemplateContext> $contexts
	 */
	public function dump(
		CompileResult $compiled,
		Declarations $declarations,
		array $contexts = [],
		string $relativePath = ''
	): string
	{
		$this->lastDiagnostics = [];
		$this->lastProviderMacroSites = [];
		$phpSource = $compiled->getPhpSource();
		if ($phpSource === null) {
			$this->lastDiagnostics = $compiled->getDiagnostics();

			return '';
		}

		$stmts = $this->processParsedStatements(
			$phpSource,
			$declarations,
			$compiled->getDiagnostics(),
			$contexts,
			$relativePath,
		);
		$printer = new Standard();

		return $printer->prettyPrintFile($stmts);
	}

	// Diagnostics from the most recent process()/dump() call: the CompileResult's own compile-time
	// ones plus FilterRewriter's unknown-filter/function findings. CachedParser returns shared,
	// already-rewritten node objects on a repeat parseString of byte-identical source, so filter
	// diagnostics surface only on the first parse of a given generated-source string per process.

	/**
	 * @return array<Diagnostic>
	 */
	public function getDiagnostics(): array
	{
		return $this->lastDiagnostics;
	}

	// ProviderMacroScanner's own two-pass split (see its class doc): scanRaw() runs inside
	// processParsedStatements() itself, before the eliminator traverser ever touches $stmts, and
	// scanForms() after it - both populate this same list, reset on every process()/dump() call
	// exactly like getDiagnostics() above. Both passes are skipped entirely when
	// $providerScanEnabled is false (see processParsedStatements()) - callers that never wire a
	// ProviderAvailabilityChecker (or run with orisaiNette.latte.discovery.enabled off) get an empty list
	// here, never a paid-for-nothing pair of NodeFinder walks.

	/**
	 * @return list<array{macro: string, provider: string, line: int}>
	 */
	public function getProviderMacroSites(): array
	{
		return $this->lastProviderMacroSites;
	}

	// Lazy: AnalysisPipeline is a constructor-injected, eagerly-instantiated dependency of
	// LatteRoutingParser (defaultAnalysisParser!), constructed on every run regardless of
	// orisaiNette.latte.enabled - harvesting here, on first actual use, keeps a disabled/unused pipeline
	// from ever paying the harvester's container-load/engine-create cost.
	private function filterTable(): FilterTable
	{
		if ($this->filterTable === null) {
			$this->filterTable = new FilterTable($this->harvested());
		}

		return $this->filterTable;
	}

	private function functionTable(): FunctionTable
	{
		if ($this->functionTable === null) {
			$this->functionTable = new FunctionTable($this->harvested());
		}

		return $this->functionTable;
	}

	private function harvested(): HarvestedCustoms
	{
		return $this->harvester !== null ? $this->harvester->harvest() : HarvestedCustoms::empty();
	}

	/**
	 * @param array<Diagnostic> $compileDiagnostics
	 * @param list<TemplateContext> $contexts
	 * @return array<Stmt>
	 */
	private function processParsedStatements(
		string $phpSource,
		Declarations $declarations,
		array $compileDiagnostics,
		array $contexts,
		string $relativePath
	): array
	{
		$stmts = $this->phpParser->parseString($phpSource);
		$adapter = $this->adapterAccessor->get();
		$lineMapper = new LineMapper($adapter->lineMarkerPattern());
		$lineMapper->remap($stmts, $lineMapper->buildMap($phpSource));

		$injectorDiagnostics = $this->declarationInjector->inject(
			$stmts,
			$declarations,
			$adapter->family(),
			$contexts,
			$relativePath,
		);

		// RAW pass, before the eliminator traverser below ever touches $stmts: BlockDispatchEliminator's
		// own snippetDriver enter()/leave() shell is DROPPED entirely (only its try body survives), so
		// a {snippet} site has no trace left to read once that traversal runs, and control/link/plink/
		// ifCurrent are read here too rather than off two different passes for no reason - see
		// ProviderMacroScanner's own class doc for the {form}/{control} ambiguities this ordering avoids.
		// Opt-in gate, same precedent as $narrowingEnabled below: no consumer ever reads a site unless
		// a ProviderAvailabilityChecker is wired AND orisaiNette.latte.discovery.enabled is on, so with the flag
		// off this (and the scanForms() pass below) never runs its NodeFinder walk at all.
		$providerMacroSites = $this->providerScanEnabled ? $this->providerMacroScanner->scanRaw($stmts) : [];

		// php-parser calls every registered visitor's leaveNode() for a given node in reverse
		// registration order, and a leaveNode() that returns an array/REMOVE_NODE short-circuits
		// that cascade (remaining, earlier-registered visitors never see the node). Eliminators
		// added here must therefore either keep their match domains disjoint (as all of the below
		// do, verified by variable-name/shape, e.g. CaptureEliminator only touches finally-assigns
		// literally named $ʟ_tmp, never $ʟ_ifA/$ʟ_try) or run in a separate NodeTraverser pass.
		// UiMacroEliminator/FormsMacroEliminator/BlockDispatchEliminator are appended last: their
		// {control}/{link}/{form}/{formContainer}/{input}/renderBlock/snippetDriver shapes never
		// start with an ob_start()/Throwable-catch pair (ControlFlowEliminator's try matchers) or a
		// bare/closure ob_start() (Capture/AttrShell's try matchers) - BlockDispatchEliminator's own
		// snippetDriver->enter(...)/try/finally shell is discriminated the same way, structurally,
		// by requiring stmt[0] to be a snippetDriver->enter(...) MethodCall (never an ob_start
		// FuncCall) - so relative registration order does not affect correctness, only readability.
		// UiMacroEliminator's {ifCurrent} shell (tryMatchIfCurrentShell/matchIfCurrentCond) is keyed
		// on a bare If_ whose cond is a MethodCall named isLinkCurrent/getLastCreatedRequestFlag on
		// $this->global->uiPresenter - it never touches a TryCatch at all, so it cannot collide with
		// any try-keyed matcher above, and its condition shape is disjoint from {control}'s own
		// Assign/If_-with-instanceof/MethodCall 3-statement window and from {link}/{plink}'s
		// single-node MethodCall match (different method name). BlockDispatchEliminator's {embed}
		// shell (tryMatchEmbedShell/matchEnterBlockLayer/matchCopyBlockLayer/matchLeaveBlockLayerShell)
		// is keyed on an enterBlockLayer(...) MethodCall on $this, followed by an If_(false), an
		// optional copyBlockLayer() MethodCall, then a TryCatch whose finally is a single
		// leaveBlockLayer() call - disjoint from its own snippetDriver->enter(...) shell (which
		// requires the statement immediately before the TryCatch to be that specific MethodCall,
		// never enterBlockLayer/copyBlockLayer) and from every ob_start-keyed try matcher above (an
		// enterBlockLayer/copyBlockLayer MethodCall or an If_ can never satisfy a FuncCall named
		// ob_start).
		$family = $adapter->family();
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new PrologEliminator($family));
		$traverser->addVisitor(new EscapingEliminator($family));
		$traverser->addVisitor(new IteratorEliminator($family));
		$traverser->addVisitor(new ControlFlowEliminator($family));
		$traverser->addVisitor(new DevTagEliminator($family));
		$traverser->addVisitor(new CaptureEliminator($family));
		$traverser->addVisitor(new AttrShellEliminator($family));
		$traverser->addVisitor(new UiMacroEliminator($family));
		$traverser->addVisitor(new FormsMacroEliminator($family));
		$traverser->addVisitor(new BlockDispatchEliminator($family));

		/** @var array<Stmt> $processed */
		$processed = $traverser->traverse($stmts);

		// POST-elimination pass: FormsMacroEliminator has already told {form x} (needs uiControl)
		// apart from {form $var} (an already-resolved object, never touching uiControl) by rewriting
		// them to two different Helpers:: methods - reading its OWN output here is what keeps that
		// split from being re-derived (and re-risked) a second time. Gated the same way as the RAW
		// pass above - $providerMacroSites is already [] when disabled, so there is nothing to merge.
		$this->lastProviderMacroSites = $this->providerScanEnabled
			? array_merge($providerMacroSites, $this->providerMacroScanner->scanForms($processed))
			: [];

		// FilterRewriter runs as its own pass, after every eliminator above: it needs the
		// CaptureEliminator-normalized $ʟ_tmp/filterContent shapes already in their final form.
		// $declarations is this FILE's own header declaration, so the per-template overlay it
		// unlocks is strictly scoped to this compiled class and the context clones cloned from it
		// below - never to an unrelated file that happens to declare the same {templateType}.
		// FilterTable/FunctionTable are read from Latte 2's Latte\Runtime\Defaults; a Latte 3 family
		// keeps its filter and function calls as compiled.
		$filterDiagnostics = $family->latteLine === ShapeFamily::LATTE_2
			? (new FilterRewriter($family))->rewrite(
				$processed,
				$this->filterTable(),
				$this->functionTable(),
				$declarations->getTemplateTypeClass(),
				$this->templateTypeCustoms,
			)
			: [];
		$this->lastDiagnostics = array_merge($compileDiagnostics, $injectorDiagnostics, $filterDiagnostics);

		// Last, after every eliminator/rewriter above has settled the AST shape: cloning already
		// happened inside DeclarationInjector::inject() (before the eliminator traverser), so every
		// latteMain_ctx{i} this includer has already exists by this point and gets its own anchors.
		// Opt-in gate: with narrowing disabled, no anchor is ever materialized - the generated body
		// is byte-identical to pre-narrowing-plan output.
		if ($this->narrowingEnabled) {
			$this->edgeAnchorInjector->inject($processed, $relativePath, $contexts);
		}

		return $processed;
	}

}
