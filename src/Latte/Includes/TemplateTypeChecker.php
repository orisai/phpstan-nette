<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use LogicException;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingVerdict;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\ViewFact;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\ReflectionProvider;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function basename;
use function count;
use function dirname;
use function implode;
use function in_array;
use function is_string;
use function ksort;
use function lcfirst;
use function sort;
use function sprintf;
use function strncmp;
use function strpos;
use function strrpos;
use function substr;
use const SORT_STRING;

// The Latte-side half of template-file discovery: the store's class->file links finally have a
// consumer that reads them FROM the template. Four identifiers, ALL of them answered by
// checkAggregate(), once per analysis over merged collected data (LatteTemplateGraphRule).
//
// THE CONSTRAINT THIS PLACEMENT ENCODES - the code cannot show it, so it is written down. Every one
// of the four reads a fact derived from files OTHER than the template it reports on, through a
// channel PHPStan's result cache does not propagate:
//   - orisaiNette.latte.orphanTemplate / orisaiNette.latte.templateMissing are GLOBAL AGGREGATES: reachability is a fixpoint
//     over the whole template graph and the missing-view host is the renderer's lexicographically
//     first linked template across the whole store, so a warm run that reanalysed only the changed
//     files could serve a verdict computed from a graph that no longer exists.
//   - orisaiNette.latte.templateTypeMismatch / orisai.nette.latte.templateTypeRequired compare the template's own
//     {templateType} against the renderer's PAIRING VERDICT, whose primary channel
//     (PhpRenderWalk::CONVENTION_HOOK_METHODS) is a `return X::class;` inside a METHOD BODY, and
//     against the class HIERARCHY of the paired class. A body-only edit leaves every signature
//     byte-identical, so ResultCacheManager's exportedNodesChanged() returns null and its
//     dependent-files loop is never reached; the paired class is not a dependency of the template
//     at all (emitTemplateTypeRef refs only the DECLARED class, emitDiscoveryRefs only the record's
//     renderer classes). Neither fact moves a discovery record either, so the store file's
//     RECORDS_HASH - the one exported node that does carry store changes across - stays identical
//     and the documented one-run-later recovery never fires. Answered per-file, both went
//     PERMANENTLY stale in both directions after a one-line body edit; see
//     tests/Integration/Latte/Invalidation for the reproduction as a committed test.
// CollectedDataNode rules run in AnalyserResultFinalizer, after the result cache has been restored
// AND saved, over cached-plus-fresh per-file data that is re-aggregated in full every run - so this
// placement is cold == warm by construction, with no whole-cache salt and no invalidation edge,
// which is what keeps the discovery store's ratified propagation granularity untouched. Moving any
// of the four back to a per-file check reopens the hole.
//
// Every identifier needs both the analysis flag and the discovery store - without records there are
// no class->file links at all, and the reachability half seeded from an empty live-root set would
// report the entire app corpus.
// The record source and the pairing judge are resolved lazily BY SERVICE NAME (DiscoveryRefResolver's
// own precedent): the routing parser holds this checker, so a constructor-eager walk or judge would
// chain reflectionProvider -> source locators -> defaultAnalysisParser back into that parser's own
// construction.
final class TemplateTypeChecker
{

	public const MISMATCH_IDENTIFIER = 'orisaiNette.latte.templateTypeMismatch';

	public const MISSING_IDENTIFIER = 'orisaiNette.latte.templateMissing';

	public const REQUIRED_IDENTIFIER = 'orisaiNette.latte.templateTypeRequired';

	public const ORPHAN_IDENTIFIER = 'orisaiNette.latte.orphanTemplate';

	public const RECORD_SOURCE_SERVICE_NAME = 'latteDiscoveryRecordSource';

	public const PAIRING_JUDGE_SERVICE_NAME = 'lattePairingJudge';

	public const REFLECTION_PROVIDER_SERVICE_NAME = 'reflectionProvider';

	// A view seed's own source method name; the render dispatch's entry point for that view.
	private const RENDER_METHOD_PREFIX = 'render';

	private const LATTE_SUFFIX = '.latte';

	// The fallback rungs of SP1's template-class ladder - never a walked observation, so a verdict
	// resting on one means nothing in the renderer named a template class at all.
	private const FLOOR_CHANNELS = [
		TemplateClassFact::CHANNEL_FACTORY_DEFAULT,
		TemplateClassFact::CHANNEL_TEMPLATE_FLOOR,
	];

	private Container $container;

	private DiscoveryStore $store;

	private TemplateEdgeIndex $index;

	private LatteUniverse $universe;

	private FirstPartyPaths $firstPartyPaths;

	private bool $enabled;

	private bool $discoveryStoreEnabled;

	private bool $templateTypeRequired;

	private ?DiscoveryRecordSource $recordSource = null;

	private ?PairingJudge $judge = null;

	private ?ReflectionProvider $reflectionProvider = null;

	/** @var array<string, true>|null */
	private ?array $liveTemplates = null;

	/** @var array<string, string>|null */
	private ?array $firstLinkedTemplates = null;

	/** @var list<array{class: string, name: string}> */
	private array $conventionNameSites = [];

	/** @var array<string, true> */
	private array $terminatingRenderMethods = [];

	/**
	 * @param list<string> $firstPartyPaths
	 */
	public function __construct(
		Container $container,
		DiscoveryStore $store,
		TemplateEdgeIndex $index,
		LatteUniverse $universe,
		array $firstPartyPaths,
		bool $enabled,
		bool $discoveryStoreEnabled,
		bool $templateTypeRequired
	)
	{
		$this->container = $container;
		$this->store = $store;
		$this->index = $index;
		$this->universe = $universe;
		$this->firstPartyPaths = new FirstPartyPaths($firstPartyPaths);
		$this->enabled = $enabled;
		$this->discoveryStoreEnabled = $discoveryStoreEnabled;
		$this->templateTypeRequired = $templateTypeRequired;
	}

	// All four identifiers, answered once per analysis rather than once per parsed template. Every
	// input arrives here fresh: the analysed template set, each template's own {templateType}
	// declaration and the two facts-side inputs below come from PHPStan's merged collected data
	// (cached per file, re-aggregated every run), the edge index rebuilds from the .latte universe
	// and the store rereads from disk. There is therefore no window in which a template keeps a
	// verdict derived from inputs that have since changed - the property a per-file check cannot
	// have without a whole-cache salt, which the store's granularity doctrine forbids.

	/**
	 * @param list<string> $analysedTemplates
	 * @param list<array{path: string, class: string|null, line: int}> $templateTypeDeclarations
	 * @param list<array{class: string, name: string}> $conventionNameSites
	 * @param list<string> $terminatingRenderMethods
	 * @return list<array{file: string, diagnostic: Diagnostic}>
	 */
	public function checkAggregate(
		array $analysedTemplates,
		array $templateTypeDeclarations,
		array $conventionNameSites,
		array $terminatingRenderMethods
	): array
	{
		if (!$this->enabled || !$this->discoveryStoreEnabled) {
			return [];
		}

		// With no class->file link anywhere the liveness fixpoint has no roots to start from and the
		// reachability half would report the ENTIRE analysed corpus as orphaned - a dead verdict on
		// demonstrably live files. Deliberately phrased on the LINK SET rather than on is_dir(),
		// which it subsumes (an absent store directory loads as no entries): the same precondition
		// then also covers a store bootstrapped with empty per-template files but no records yet.
		if ($this->store->allLinkedTemplates() === []) {
			return [];
		}

		$this->conventionNameSites = $conventionNameSites;
		$this->terminatingRenderMethods = array_fill_keys($terminatingRenderMethods, true);
		$this->liveTemplates = null;
		$this->firstLinkedTemplates = null;

		// Collected data arrives in whatever order the workers finished in - the one input layer no
		// canonicalization upstream of here flattens, so the output order is established here.
		$templates = [];
		foreach ($analysedTemplates as $relPath) {
			$templates[$relPath] = true;
		}

		ksort($templates, SORT_STRING);

		$declarationsByPath = [];
		foreach ($templateTypeDeclarations as $declaration) {
			$declarationsByPath[$declaration['path']] = $declaration;
		}

		$missingByHost = $this->missingByHost();

		$findings = [];
		foreach (array_keys($templates) as $relPath) {
			$file = $this->universe->projectRoot() . '/' . $relPath;

			foreach ($missingByHost[$relPath] ?? [] as $diagnostic) {
				$findings[] = ['file' => $file, 'diagnostic' => $diagnostic];
			}

			$declaration = $declarationsByPath[$relPath] ?? null;
			if ($declaration !== null) {
				foreach ($this->checkRenderers($declaration['class'], $declaration['line'], $relPath) as $diagnostic) {
					$findings[] = ['file' => $file, 'diagnostic' => $diagnostic];
				}
			}

			foreach ($this->checkReachability($relPath) as $diagnostic) {
				$findings[] = ['file' => $file, 'diagnostic' => $diagnostic];
			}
		}

		return $findings;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function checkRenderers(
		?string $templateTypeClass,
		int $templateTypeLine,
		string $projectRelativePath
	): array
	{
		$diagnostics = [];
		foreach ($this->linkedClasses($projectRelativePath) as $className) {
			$facts = $this->recordSource()->factsFor($className);

			// Shared qualification gate: judge() throws on non-qualifying facts by ratified
			// contract, and a non-qualifying class carries no discovery fact either.
			if (!Qualification::qualifies($facts)) {
				continue;
			}

			$verdict = $this->judge()->judge($facts);

			// An opaque verdict - the *dynamic* marker among them - states nothing about which
			// class the renderer pairs, so both checks below stay OPEN rather than comparing
			// against a name that is not one.
			if (
				$verdict->getOpaques() !== []
				|| $verdict->getPrimaryClass() === TemplateClassFact::DYNAMIC_CLASS_NAME
			) {
				continue;
			}

			$diagnostics = array_merge(
				$diagnostics,
				$templateTypeClass === null
					? $this->checkTemplateTypeRequired($className, $verdict)
					: $this->checkTemplateTypeMismatch(
						$templateTypeClass,
						$className,
						$verdict,
						$templateTypeLine,
					),
			);
		}

		return $diagnostics;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function checkTemplateTypeMismatch(
		string $templateTypeClass,
		string $className,
		PairingVerdict $verdict,
		int $templateTypeLine
	): array
	{
		$primaryClass = $verdict->getPrimaryClass();
		$reflectionProvider = $this->reflectionProvider();

		// orisaiNette.latte.unknownType already reports an unresolvable {templateType} once, at its declaring
		// file; a vanished pairing target is LattePairingRule's opaque finding. Either side missing
		// makes the subtype question unanswerable, never answered wrongly - PairingJudge's own
		// unknown-class discipline, through the same reflection surface it uses.
		if (!$reflectionProvider->hasClass($templateTypeClass) || !$reflectionProvider->hasClass($primaryClass)) {
			return [];
		}

		// The owner's standing ruling: a declaration may WIDEN. Every member the body reads off the
		// declared class exists on any subtype, so only a primary OUTSIDE the declared type reports.
		if ($reflectionProvider->getClass($primaryClass)->is($templateTypeClass)) {
			return [];
		}

		return [
			new Diagnostic(
				self::MISMATCH_IDENTIFIER,
				sprintf(
					'Template declares {templateType %s} but renderer %s pairs %s.',
					$templateTypeClass,
					$className,
					$primaryClass,
				),
				$templateTypeLine,
			),
		];
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function checkTemplateTypeRequired(string $className, PairingVerdict $verdict): array
	{
		if (!$this->templateTypeRequired || !in_array($verdict->getPrimaryChannel(), self::FLOOR_CHANNELS, true)) {
			return [];
		}

		return [
			new Diagnostic(
				self::REQUIRED_IDENTIFIER,
				sprintf(
					'Template has no {templateType} and renderer %s pairs the default template class.',
					$className,
				),
				1,
			),
		];
	}

	// The finding belongs to the RENDERER, not to any one of its templates: a class linked to
	// several existing templates would otherwise report the same missing view once per template.
	// The renderer's lexicographically first linked template hosts it - the store's own canonical
	// order makes that choice stable across runs. The host choice is a whole-store read, which is
	// why it is grouped here rather than re-asked per parsed template: a template that stops being
	// the first linked one has to LOSE the finding in the same run the new host gains it.

	/**
	 * @return array<string, list<Diagnostic>>
	 */
	private function missingByHost(): array
	{
		$classNames = array_keys($this->firstLinkedTemplates());
		sort($classNames, SORT_STRING);

		$byHost = [];
		foreach ($classNames as $className) {
			$facts = $this->recordSource()->factsFor($className);

			// Shared qualification gate: a non-qualifying class carries no discovery fact either.
			if (!Qualification::qualifies($facts)) {
				continue;
			}

			foreach ($this->missingDiagnostics($className, $facts) as $diagnostic) {
				$byHost[$this->firstLinkedTemplates()[$className]][] = $diagnostic;
			}
		}

		return $byHost;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function missingDiagnostics(string $className, PhpRenderFacts $facts): array
	{
		$discovery = $facts->getDiscovery();

		// OPEN, never false-close (spec section 9): an opaque locator or a non-literal setView
		// argument means the real candidate set is unknown, not empty.
		if ($discovery === null || $discovery->getOpaques() !== [] || $facts->hasOpenViewSet()) {
			return [];
		}

		// An abstract renderer is never the class that runs: Nette's own PresenterFactory rejects
		// an abstract presenter class outright, and no control can be instantiated either, so its
		// render hooks reach no dispatch. Its concrete descendants carry their own facts and their
		// own candidates, and report on their own account.
		$reflectionProvider = $this->reflectionProvider();
		if ($reflectionProvider->hasClass($className) && $reflectionProvider->getClass($className)->isAbstract()) {
			return [];
		}

		$diagnostics = [];
		foreach ($discovery->getViewCandidates() as $view => $candidates) {
			if (
				$candidates === []
				|| !$this->resolvesATemplateFile($className, $facts->getViews()[$view] ?? null, $candidates)
			) {
				continue;
			}

			foreach ($candidates as $candidate) {
				if ($candidate->exists()) {
					continue 2;
				}
			}

			$diagnostics[] = new Diagnostic(
				self::MISSING_IDENTIFIER,
				sprintf(
					'No template file found for %s::%s (tried: %s).',
					$className,
					$view,
					implode(', ', array_map(
						static fn ($candidate): string => $candidate->getPath(),
						$candidates,
					)),
				),
				1,
			);
		}

		return $diagnostics;
	}

	// A view only PROVABLY reaches template-file resolution when the renderer wrote a file for it
	// explicitly (a setFile/convention candidate - that write happened, whatever comes after) or
	// when the presenter defines the render<View> dispatch hook the vendor calls right before
	// sendTemplate(). An action<View>-only view proves nothing: the action may redirect, forward,
	// sendJson or terminate long before formatTemplateFiles() is ever consulted. The render side is
	// held to the SAME standard - a render<View> whose body always terminates (PHPStan's own
	// earlyTerminatingMethodCalls vocabulary, collected per method) never returns to the dispatch
	// either, so its existence proves nothing. OPEN, never false-close.

	/**
	 * @param list<CandidatePath> $candidates
	 */
	private function resolvesATemplateFile(string $className, ?ViewFact $viewFact, array $candidates): bool
	{
		foreach ($candidates as $candidate) {
			if ($candidate->getKind() !== CandidatePath::KIND_FORMULA) {
				return true;
			}
		}

		if ($viewFact === null) {
			return false;
		}

		foreach ($viewFact->getSources() as $source) {
			if (
				is_string($source)
				&& strpos($source, self::RENDER_METHOD_PREFIX) === 0
				&& !$this->alwaysTerminates($className, $source)
			) {
				return true;
			}
		}

		return false;
	}

	// Keyed by the method's DECLARING class, so an inherited render hook is recognized through the
	// class that actually declares its body - the class the collector saw it in.
	private function alwaysTerminates(string $className, string $methodName): bool
	{
		if ($this->terminatingRenderMethods === []) {
			return false;
		}

		$reflectionProvider = $this->reflectionProvider();
		if (!$reflectionProvider->hasClass($className)) {
			return false;
		}

		$native = $reflectionProvider->getClass($className)->getNativeReflection();
		if (!$native->hasMethod($methodName)) {
			return false;
		}

		$declaring = $native->getMethod($methodName)->getDeclaringClass()->getName();

		return array_key_exists($declaring . '::' . $methodName, $this->terminatingRenderMethods);
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function checkReachability(string $projectRelativePath): array
	{
		if (
			!$this->inAppScope($projectRelativePath)
			|| array_key_exists($projectRelativePath, $this->liveTemplates())
		) {
			return [];
		}

		// NO FIXER, EVER (spec section 9b): dead-code-detector may auto-fix because its assumptions
		// over-approximate USAGE, which makes deletion conservative. This check has the opposite
		// bias - it UNDER-detects usage (opaque setFile arguments, dynamic includes, unassigned
		// locators, channels not modelled at all) - so a reported orphan may be genuinely rendered
		// at runtime, and "we failed to detect the usage" is not distinguishable here from
		// "certainly dead". A fixer acting on this would delete live files.
		// The subject is this FILE's discovery, never its {templateType}: the finding says the
		// analysis found no render call, no include and no layout hop leading here.
		return [
			new Diagnostic(
				self::ORPHAN_IDENTIFIER,
				'No analysable render, include or layout path reaches this template file.',
				1,
				$this->unreachableIncludersTip($projectRelativePath),
			),
		];
	}

	// Every non-discovery incoming edge of an orphan comes from a template that is itself orphaned:
	// a live includer would have propagated its own liveness down this very edge. Naming them is
	// what turns "this partial looks dead" into "and here is the dead branch that kept it alive" -
	// a tip, never part of the message, because tips take no part in baseline matching.
	private function unreachableIncludersTip(string $projectRelativePath): ?string
	{
		$includers = [];
		foreach ($this->index->incomingEdges($projectRelativePath) as $edge) {
			// A discovery marker's includer is a renderer CLASS NAME, and its presence would have
			// made this template a live root in the first place.
			if ($edge['site']->getKind() === IncludeTarget::KIND_DISCOVERY) {
				continue;
			}

			$includers[$edge['includer']] = true;
		}

		if ($includers === []) {
			return null;
		}

		$names = array_keys($includers);
		sort($names, SORT_STRING);

		return sprintf('Included only from templates that are themselves unreachable: %s.', implode(', ', $names));
	}

	// Liveness fixpoint over the WHOLE template graph, computed once per run: live roots are the
	// templates a PHP renderer renders (every store record, plus every existing candidate the facts
	// know of - see renderedByFacts() - plus the convention-name overrides of conventionNameRoots()),
	// and liveness propagates through every static include/import/extends site plus the
	// discovery-derived auto-layout edges. Breadth-first over a queue with a visited set - layout
	// cycles exist in this corpus, and the visited set is what makes them terminate. Deterministic by
	// construction: the root union is sorted before the walk starts and each node's successors are
	// sorted before they are queued, so the reached set is identical whatever order the store's own
	// iteration produced.

	/**
	 * @return array<string, true>
	 */
	private function liveTemplates(): array
	{
		if ($this->liveTemplates !== null) {
			return $this->liveTemplates;
		}

		$roots = [];
		foreach ($this->store->allLinkedTemplates() as $rel) {
			$roots[$rel] = true;
		}

		foreach ($this->renderedByFacts() as $rel) {
			$roots[$rel] = true;
		}

		foreach ($this->conventionNameRoots() as $rel) {
			$roots[$rel] = true;
		}

		$queue = array_keys($roots);
		sort($queue, SORT_STRING);

		$live = $roots;

		for ($index = 0; $index < count($queue); $index++) {
			foreach ($this->successors($queue[$index]) as $target) {
				if (array_key_exists($target, $live)) {
					continue;
				}

				$live[$target] = true;
				$queue[] = $target;
			}
		}

		return $this->liveTemplates = $live;
	}

	// Every EXISTING candidate of every indexed renderer class, not only the CHOSEN one the store
	// records. `chosen` answers "which file does this view resolve to", which is the wrong question
	// for reachability: a control whose render() and renderTable() each setFile their own template
	// reaches BOTH files, and a convention candidate outranking them in the chosen-selection does not
	// make them unrendered. Read from the facts envelope rather than the store, exactly as
	// DiscoveryRecords' own chosen-and-existing-only contract directs its non-store consumers.
	// Absolute paths are candidates that escaped the project root - no analysed template carries one.

	/**
	 * @return list<string>
	 */
	private function renderedByFacts(): array
	{
		$rendered = [];
		foreach ($this->store->linkedClasses() as $className) {
			$discovery = $this->recordSource()->factsFor($className)->getDiscovery();
			if ($discovery === null) {
				continue;
			}

			$candidateLists = $discovery->getViewCandidates();
			$candidateLists[] = $discovery->getLayoutCandidates();

			foreach ($candidateLists as $candidates) {
				foreach ($candidates as $candidate) {
					$path = $candidate->getPath();
					if (!$candidate->exists() || strncmp($path, '/', 1) === 0) {
						continue;
					}

					$rendered[$path] = true;
				}
			}
		}

		$paths = array_keys($rendered);
		sort($paths, SORT_STRING);

		return $paths;
	}

	// The SETTER half of the convention channel FormulaVocabulary already documents as a projected
	// runtime input: a convention locator derives <dir>/lcfirst($this->file).latte, and $this->file
	// defaults to the short class name only until somebody writes it. The write is usually made from
	// OUTSIDE the class (a component factory in the presenter that owns the control), so no per-class
	// walk can ever see it; a collector over the real call sites can, with the receiver's own type
	// answering which class's convention directory the name lands in. Only the BASENAME is
	// parameterized by $this->file, so the derived candidate is rewritten in place, and only the
	// candidate whose basename is the class's own default is rewritten - the shared-fallback
	// locators' fixed second candidate is not a $this->file function. Liveness roots only, never
	// records: an over-approximated root can only SILENCE an orphan finding (the ratified safe
	// direction for a check that carries no fixer), while a record would feed chosen-selection,
	// pairing and templateMissing, which need the opposite bias.

	/**
	 * @return list<string>
	 */
	private function conventionNameRoots(): array
	{
		$roots = [];
		foreach ($this->conventionNameSites as $site) {
			$discovery = $this->recordSource()->factsFor($site['class'])->getDiscovery();
			if ($discovery === null) {
				continue;
			}

			$defaultBasename = lcfirst(self::shortClassName($site['class'])) . self::LATTE_SUFFIX;
			$overrideBasename = lcfirst($site['name']) . self::LATTE_SUFFIX;

			foreach ($discovery->getViewCandidates() as $candidates) {
				foreach ($candidates as $candidate) {
					$path = $candidate->getPath();
					if (
						$candidate->getKind() !== CandidatePath::KIND_CONVENTION
						|| strncmp($path, '/', 1) === 0
						|| basename($path) !== $defaultBasename
					) {
						continue;
					}

					$directory = dirname($path);
					$override = $directory === '.' ? $overrideBasename : $directory . '/' . $overrideBasename;

					if ($this->universe->contains($this->universe->projectRoot() . '/' . $override)) {
						$roots[$override] = true;
					}
				}
			}
		}

		$paths = array_keys($roots);
		sort($paths, SORT_STRING);

		return $paths;
	}

	private static function shortClassName(string $className): string
	{
		$separator = strrpos($className, '\\');

		return $separator === false ? $className : (string) substr($className, $separator + 1);
	}

	/**
	 * @return list<string>
	 */
	private function successors(string $projectRelativePath): array
	{
		$targets = [];
		foreach ($this->index->outgoingSites($projectRelativePath) as $site) {
			$resolvedPath = $site->getResolvedPath();
			if ($resolvedPath === null || !$this->index->targetExists($site)) {
				continue;
			}

			$targets[$resolvedPath] = true;
		}

		foreach ($this->index->autoLayoutAncestors($projectRelativePath) as $layoutRel) {
			$targets[$layoutRel] = true;
		}

		$list = array_keys($targets);
		sort($list, SORT_STRING);

		return $list;
	}

	private function inAppScope(string $projectRelativePath): bool
	{
		return $this->firstPartyPaths->contains(
			$this->universe->projectRoot() . '/' . $projectRelativePath,
		);
	}

	/**
	 * @return list<string>
	 */
	private function linkedClasses(string $projectRelativePath): array
	{
		$classNames = [];
		foreach ($this->store->recordsForTemplate($projectRelativePath) as $record) {
			$classNames[$record['class']] = true;
		}

		return array_keys($classNames);
	}

	/**
	 * @return array<string, string>
	 */
	private function firstLinkedTemplates(): array
	{
		if ($this->firstLinkedTemplates === null) {
			$firstLinked = [];
			foreach ($this->store->allLinkedTemplates() as $relPath) {
				foreach ($this->store->recordsForTemplate($relPath) as $record) {
					if (array_key_exists($record['class'], $firstLinked)) {
						continue;
					}

					$firstLinked[$record['class']] = $relPath;
				}
			}

			$this->firstLinkedTemplates = $firstLinked;
		}

		return $this->firstLinkedTemplates;
	}

	private function recordSource(): DiscoveryRecordSource
	{
		if ($this->recordSource === null) {
			$recordSource = $this->container->getService(self::RECORD_SOURCE_SERVICE_NAME);
			if (!$recordSource instanceof DiscoveryRecordSource) {
				throw new LogicException(
					self::RECORD_SOURCE_SERVICE_NAME . ' must be a DiscoveryRecordSource service.',
				);
			}

			$this->recordSource = $recordSource;
		}

		return $this->recordSource;
	}

	private function judge(): PairingJudge
	{
		if ($this->judge === null) {
			$judge = $this->container->getService(self::PAIRING_JUDGE_SERVICE_NAME);
			if (!$judge instanceof PairingJudge) {
				throw new LogicException(self::PAIRING_JUDGE_SERVICE_NAME . ' must be a PairingJudge service.');
			}

			$this->judge = $judge;
		}

		return $this->judge;
	}

	private function reflectionProvider(): ReflectionProvider
	{
		if ($this->reflectionProvider === null) {
			$reflectionProvider = $this->container->getService(self::REFLECTION_PROVIDER_SERVICE_NAME);
			if (!$reflectionProvider instanceof ReflectionProvider) {
				throw new LogicException(
					self::REFLECTION_PROVIDER_SERVICE_NAME . ' must be a ReflectionProvider service.',
				);
			}

			$this->reflectionProvider = $reflectionProvider;
		}

		return $this->reflectionProvider;
	}

}
