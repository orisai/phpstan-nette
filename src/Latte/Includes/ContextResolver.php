<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
use function ksort;
use function strpos;
use const SORT_STRING;

final class ContextResolver
{

	private const DEPTH_CAP = 16;

	private const FACTORY_PROVENANCE_PREFIX = 'factory:';

	private TemplateEdgeIndex $index;

	private LatteUniverse $universe;

	private ArgTyper $argTyper;

	private DeclaredVarsResolver $declaredVarsResolver;

	private CapturedOverlay $capturedOverlay;

	private ?FactoryProvidedVars $factoryProvidedVars;

	private bool $includeIsolation;

	/** @var array<string, list<TemplateContext>> */
	private array $memo = [];

	/** @var array<string, true> */
	private array $stack = [];

	/** @var array<string, string>|null */
	private ?array $relativeToAbsolute = null;

	/** @var array<string, array{includer: string, latteLine: int, target: string}> */
	private array $cutEdges = [];

	/** @var array<string, array{includer: string, latteLine: int, target: string}> */
	private array $depthCapCuts = [];

	public function __construct(
		TemplateEdgeIndex $index,
		LatteUniverse $universe,
		CapturedOverlay $capturedOverlay,
		bool $includeIsolation = false,
		?FactoryProvidedVars $factoryProvidedVars = null
	)
	{
		$this->index = $index;
		$this->universe = $universe;
		$this->argTyper = new ArgTyper($index->getAdapterAccessor());
		$this->declaredVarsResolver = new DeclaredVarsResolver($index);
		$this->capturedOverlay = $capturedOverlay;
		$this->includeIsolation = $includeIsolation;
		$this->factoryProvidedVars = $factoryProvidedVars;
	}

	/**
	 * @return list<TemplateContext>
	 */
	public function contextsFor(string $projectRelativePath): array
	{
		return $this->resolveContexts($projectRelativePath);
	}

	/**
	 * @return list<array{includer: string, latteLine: int, target: string}>
	 */
	public function cutCycleEdges(): array
	{
		return array_values($this->cutEdges);
	}

	// A depth-cap cut (a straight, non-cyclic chain deeper than DEPTH_CAP) is a DIFFERENT failure
	// mode from a true on-stack cycle cut: which edge gets cut depends on DFS visitation order,
	// which is caller/entry-point dependent for a long enough chain - reporting it as
	// orisaiNette.latte.includeCycle would make that diagnostic worker/traversal-order-dependent. Kept in its
	// own bucket so cutCycleEdges() stays exclusively true-cycle cuts.

	/**
	 * @return list<array{includer: string, latteLine: int, target: string}>
	 */
	public function depthCapCuts(): array
	{
		return array_values($this->depthCapCuts);
	}

	/**
	 * @return list<TemplateContext>
	 */
	private function resolveContexts(string $rel): array
	{
		return $this->resolveContextsTracked($rel)['contexts'];
	}

	// A node is "tainted" when its own resolution - or any child it recursed into - had an edge
	// truncated by the stack-cut guard below. A cut's outcome depends on which files are already
	// on the DFS stack, which depends on the caller's entry point, so a tainted node's result is
	// call-order-dependent and must never be memoized: caching it would let a later, differently
	// -ordered call read back a truncated result computed for a different traversal. Untainted
	// nodes are a pure function of the (fixed) graph and are safe to memoize permanently.

	/**
	 * @return array{contexts: list<TemplateContext>, tainted: bool}
	 */
	private function resolveContextsTracked(string $rel): array
	{
		if (isset($this->memo[$rel])) {
			return ['contexts' => $this->memo[$rel], 'tainted' => false];
		}

		$this->stack[$rel] = true;
		$targetAbsolute = $this->absoluteFor($rel);

		$contexts = [];
		$tainted = false;
		foreach ($this->index->incomingEdges($rel) as $edge) {
			$includerRel = $edge['includer'];
			$site = $edge['site'];

			// A discovery marker names a renderer CLASS, not a template, and carries no variable
			// payload by construction - it can neither be recursed into nor contribute a context, so
			// it is dropped before the cycle/depth bookkeeping rather than after (a marker reaching
			// the depth cap would otherwise taint this node's memoization for nothing).
			if ($site->getKind() === IncludeTarget::KIND_DISCOVERY) {
				continue;
			}

			if (isset($this->stack[$includerRel])) {
				$this->recordCutEdge($includerRel, $site->getLatteLine(), $rel);
				$tainted = true;

				continue;
			}

			if (count($this->stack) >= self::DEPTH_CAP) {
				$this->recordDepthCapCut($includerRel, $site->getLatteLine(), $rel);
				$tainted = true;

				continue;
			}

			$includerAbsolute = $this->absoluteFor($includerRel);
			if ($includerAbsolute === null) {
				continue;
			}

			$includerResult = $this->resolveContextsTracked($includerRel);
			if ($includerResult['tainted']) {
				$tainted = true;
			}

			foreach ($includerResult['contexts'] as $includerContext) {
				$contexts[] = $this->buildEdgeContext(
					$includerRel,
					$includerAbsolute,
					$site,
					$includerContext,
					$targetAbsolute,
				);
			}
		}

		unset($this->stack[$rel]);

		if ($contexts === []) {
			$contexts = [
				TemplateContext::root(
					$this->declaredVarsResolver->forFile($targetAbsolute),
					$this->declaredVarsResolver->provenanceForFile($targetAbsolute),
				),
			];
		}

		$resolved = $this->dedupeAndSort($this->overlayFactoryVars($rel, $contexts));

		if (!$tainted) {
			$this->memo[$rel] = $resolved;
		}

		return ['contexts' => $resolved, 'tainted' => $tainted];
	}

	// The WEAKEST layer of every context this file has: TemplateFactory injects its variables into
	// the template object before rendering, so they are ambient, and anything the file declares or
	// an include site passes explicitly wins over them by construction (existing keys are never
	// overwritten below). Applied to edge contexts too, not only the root one: a template the store
	// links to a renderer is rendered directly whatever else includes it, and under Latte 2 an
	// include hands the target the includer's whole param set anyway, so the variables reach it
	// through the includer's own contexts as well.

	/**
	 * @param list<TemplateContext> $contexts
	 * @return list<TemplateContext>
	 */
	private function overlayFactoryVars(string $rel, array $contexts): array
	{
		if ($this->factoryProvidedVars === null) {
			return $contexts;
		}

		$factoryVars = $this->factoryProvidedVars->typesFor($rel);
		if ($factoryVars === []) {
			return $contexts;
		}

		$factoryProvenance = $this->factoryProvidedVars->provenanceFor($rel);

		$overlaid = [];
		foreach ($contexts as $context) {
			$vars = $context->getVars();
			$provenance = $context->getProvenance();
			foreach ($factoryVars as $name => $type) {
				if (array_key_exists($name, $vars)) {
					continue;
				}

				$vars[$name] = $type;
				$provenance[$name] = $factoryProvenance[$name] ?? 'factory:?';
			}

			$overlaid[] = new TemplateContext($vars, $context->getChain(), $provenance);
		}

		return $overlaid;
	}

	// Keyed by the cut edge's own identity so a tainted node re-resolving on a later call (never
	// memoized - see resolveContextsTracked()) re-discovers the same cuts without duplicating
	// them; cutCycleEdges() must stay stable across repeated calls to the same entry point.
	private function recordCutEdge(string $includerRel, int $latteLine, string $target): void
	{
		$key = $includerRel . '#' . $latteLine . '#' . $target;
		$this->cutEdges[$key] = ['includer' => $includerRel, 'latteLine' => $latteLine, 'target' => $target];
	}

	private function recordDepthCapCut(string $includerRel, int $latteLine, string $target): void
	{
		$key = $includerRel . '#' . $latteLine . '#' . $target;
		$this->depthCapCuts[$key] = ['includer' => $includerRel, 'latteLine' => $latteLine, 'target' => $target];
	}

	private function buildEdgeContext(
		string $includerRel,
		string $includerAbsolute,
		IncludeTarget $site,
		TemplateContext $context,
		?string $targetAbsolute
	): TemplateContext
	{
		$scope = EdgeScope::resolve(
			$site,
			$context,
			$this->argTyper,
			fn (): array => $this->topLevelVars($includerAbsolute),
			$this->includeIsolation,
		);
		$open = $scope['open'];

		$provenance = $this->edgeProvenance($includerRel, $includerAbsolute, $site, $context, $scope['namedKeys']);

		// Narrowing overlay, delegated to the shared helper so ContextResolver and
		// IncludeContractChecker can never independently drift (see EdgeScope's own drift-warning
		// comment for the hazard this avoids). The declared-target overlay below always runs after
		// and always wins per variable, so a captured type can never override a target's own
		// declaration, by construction alone.
		$overlaid = $this->capturedOverlay->overlay(
			$scope['vars'],
			$scope['namedKeys'],
			$includerRel,
			$includerAbsolute,
			$site->getLatteLine(),
			$site->getRawTarget(),
			$context->canonicalHash(),
		);
		$vars = $overlaid['vars'];
		$namedKeys = $overlaid['namedKeys'];

		foreach (array_keys($overlaid['overlaidNames']) as $name) {
			$provenance[$name] = 'captured:' . $includerRel . '#' . $site->getLatteLine();
		}

		$declaredTarget = $this->declaredVarsResolver->forFile($targetAbsolute);
		$declaredProvenance = $this->declaredVarsResolver->provenanceForFile($targetAbsolute);

		if ($open) {
			foreach ($declaredTarget as $name => $type) {
				if (array_key_exists($name, $namedKeys)) {
					$vars[$name] = $type;
					$provenance[$name] = $declaredProvenance[$name] ?? 'declared:varType';
				} else {
					$vars[$name] = 'mixed';
					$provenance[$name] = 'default:mixed';
				}
			}
		} else {
			$vars = array_merge($vars, $declaredTarget);
			foreach (array_keys($declaredTarget) as $name) {
				$provenance[$name] = $declaredProvenance[$name] ?? 'declared:varType';
			}
		}

		// EDGE-LOCAL, and the one factory variable pair that is: $control/$presenter name the
		// RENDERER'S OWN IDENTITY, so an includer's value describes the includer, never the target.
		// Latte 2 really does hand the target the includer's whole param set, so keeping it would not
		// be wrong - it would be one context per includer for every shared partial, since each
		// carries a different class. Measured on this corpus: app/templates/@layout.latte went from 4
		// contexts to 41, multiplying every one of its findings (and its baseline counts) by ten. The
		// target's OWN records answer for it instead, right after this in overlayFactoryVars(), and a
		// target with no records of its own is left saying nothing - a missed detection, which is the
		// safe direction. Only the still-inherited factory value is dropped: an include site passing
		// $control explicitly has overwritten the provenance and keeps its value.
		foreach (FactoryProvidedVars::RENDERER_IDENTITY_VARIABLES as $name) {
			if (strpos($provenance[$name] ?? '', self::FACTORY_PROVENANCE_PREFIX) === 0) {
				unset($vars[$name], $provenance[$name]);
			}
		}

		return new TemplateContext($vars, array_merge($context->getChain(), [$includerRel]), $provenance);
	}

	// Per-var provenance (consumed by dumpLatteVarOrigin) for the edge-provided scope ONLY - the
	// captured-overlay and declared-target overlays each layer their own label on top in
	// buildEdgeContext(), same order those overlays themselves apply in. namedKeys already identifies
	// exactly the "explicit arg" names for every tag (see EdgeScope::resolve - sandbox's vars IS its
	// namedKeys, include/embed's namedKeys is the args subset, layout/import's is empty), so the only
	// tag needing extra handling here is layout/extends' own top-level-var contribution.

	/**
	 * @param array<string, string> $namedKeys
	 * @return array<string, string>
	 */
	private function edgeProvenance(
		string $includerRel,
		string $includerAbsolute,
		IncludeTarget $site,
		TemplateContext $context,
		array $namedKeys
	): array
	{
		$provenance = $context->getProvenance();

		if (EdgeScope::isLayoutTag($site->getTag())) {
			foreach (array_keys($this->topLevelVars($includerAbsolute)) as $name) {
				$provenance[$name] = 'topLevel:' . $includerRel;
			}
		}

		foreach (array_keys($namedKeys) as $name) {
			$provenance[$name] = 'arg:' . $includerRel . '#' . $site->getLatteLine();
		}

		return $provenance;
	}

	/**
	 * @return array<string, string>
	 */
	private function topLevelVars(string $absoluteFile): array
	{
		return $this->index->factsFor($absoluteFile)->getTopLevelVars();
	}

	private function absoluteFor(string $projectRelativePath): ?string
	{
		if ($this->relativeToAbsolute === null) {
			$map = [];
			foreach ($this->universe->files() as $absolute) {
				$map[$this->universe->relativePath($absolute)] = $absolute;
			}

			$this->relativeToAbsolute = $map;
		}

		return $this->relativeToAbsolute[$projectRelativePath] ?? null;
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return list<TemplateContext>
	 */
	private function dedupeAndSort(array $contexts): array
	{
		$byHash = [];
		foreach ($contexts as $context) {
			$hash = $context->canonicalHash();
			if (!isset($byHash[$hash])) {
				$byHash[$hash] = $context;
			}
		}

		ksort($byHash, SORT_STRING);

		return array_values($byHash);
	}

}
