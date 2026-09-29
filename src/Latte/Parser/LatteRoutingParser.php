<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Parser;

use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRefResolver;
use OriPhpstan\Nette\Latte\Compile\ProjectRelativePath;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Declarations\PropertyTypeResolver;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\DeclarationConsistencyChecker;
use OriPhpstan\Nette\Latte\Includes\EdgeFingerprint;
use OriPhpstan\Nette\Latte\Includes\FactoryProvidedVars;
use OriPhpstan\Nette\Latte\Includes\IncludeContractChecker;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\ProviderAvailabilityChecker;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\VarTypePlacementChecker;
use OriPhpstan\Nette\Latte\Postprocess\AnalysisPipeline;
use OriPhpstan\Nette\Latte\Postprocess\DependencyEdgeEmitter;
use OriPhpstan\Nette\Latte\Postprocess\DiagnosticMaterializer;
use OriPhpstan\Nette\Latte\Postprocess\RichAttributeDecorator;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use PhpParser\Node\Stmt;
use PHPStan\Parser\Parser;
use ReflectionException;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function class_exists;
use function sha1;
use function sort;
use function substr_compare;
use const SORT_STRING;

final class LatteRoutingParser implements Parser
{

	private Parser $delegate;

	private LatteVersionAdapter $adapter;

	private DeclarationScanner $scanner;

	private AnalysisPipeline $pipeline;

	private ContextResolver $contextResolver;

	private IncludeContractChecker $contractChecker;

	private DeclarationConsistencyChecker $consistencyChecker;

	private TemplateEdgeIndex $edgeIndex;

	private SiteScopeStore $siteScopeStore;

	private RichAttributeDecorator $richAttributeDecorator;

	private string $projectRoot;

	private bool $enabled;

	private bool $narrowingEnabled;

	private ?DiscoveryRefResolver $discoveryRefResolver;

	private ?FactoryProvidedVars $factoryProvidedVars;

	private ?ProviderAvailabilityChecker $providerAvailabilityChecker;

	private ?BootstrapFilesLoader $bootstrapFilesLoader;

	/** @var array<string, array<Stmt>> */
	private array $parsedFiles = [];

	// Re-entrancy guard, keyed by the files parseLatteFile() has entered but not yet left; the value
	// is that file's compiled PHP source once the compiler has produced it, null before.
	//
	// Parsing a template asks the ReflectionProvider about classes (PairingJudge's candidate check,
	// TemplateTypeChecker's property reads), and when a .latte file is named DIRECTLY on the command
	// line PHPStan builds an OptimizedSingleFileSourceLocator for it - a locator that answers EVERY
	// identifier lookup by fetching that one file's nodes through this parser, and that caches its
	// symbol set only once the fetch RETURNS. So the first such lookup re-enters parseFile() for the
	// file already being parsed, which asks again, unboundedly: parsedFiles below is written on the
	// way out, too late to break the cycle. PHP 7.4 has no stack-limit detection
	// (zend.max_allowed_stack_size is 8.3+), so that overflowed the C stack and SIGSEGVed the process
	// instead of throwing. A whole-project run never reaches it: a directory is served by
	// OptimizedDirectorySourceLocator, whose class->file map comes from tokenizing the RAW file, and
	// raw Latte declares no class, so it never fetches a template's nodes at all.
	//
	// The re-entrant answer is the COMPILED CLASS ALONE - no contexts, no injected declarations, no
	// edge/fingerprint constants - because that is the whole of what the caller asked: which symbols
	// this file exports. Answering [] instead would be observably wrong rather than merely
	// approximate: the locator latches the first fetch's symbol set for the rest of the run, so the
	// template's own LatteTpl_* class would become unreflectable and PHPStan would abort the file
	// with "Class ... was not found while trying to analyse it". Nothing survives the run - every
	// osfsl-<file>-* cache key holding a .latte path is dropped by LatteReflectionCacheBypass - and
	// once the outer parse returns, the memo serves every later lookup the full AST.
	/** @var array<string, string|null> */
	private array $parsingFiles = [];

	// Memoizes declarationsFor()'s read+scan per relative path - reachableTemplateTypeClasses()
	// walks the SAME file's declarations repeatedly across different callers' recursive walks
	// (once per file that transitively includes it), and this instance is shared for the whole
	// analysis run (defaultAnalysisParser! is a single service).
	/** @var array<string, Declarations|null> */
	private array $declarationsCache = [];

	public function __construct(
		Parser $delegate,
		LatteVersionAdapter $adapter,
		DeclarationScanner $scanner,
		AnalysisPipeline $pipeline,
		ContextResolver $contextResolver,
		IncludeContractChecker $contractChecker,
		DeclarationConsistencyChecker $consistencyChecker,
		TemplateEdgeIndex $edgeIndex,
		SiteScopeStore $siteScopeStore,
		RichAttributeDecorator $richAttributeDecorator,
		string $projectRoot,
		bool $enabled,
		bool $narrowingEnabled,
		?DiscoveryRefResolver $discoveryRefResolver = null,
		?FactoryProvidedVars $factoryProvidedVars = null,
		?ProviderAvailabilityChecker $providerAvailabilityChecker = null,
		?BootstrapFilesLoader $bootstrapFilesLoader = null
	)
	{
		$this->delegate = $delegate;
		$this->adapter = $adapter;
		$this->scanner = $scanner;
		$this->pipeline = $pipeline;
		$this->contextResolver = $contextResolver;
		$this->contractChecker = $contractChecker;
		$this->consistencyChecker = $consistencyChecker;
		$this->edgeIndex = $edgeIndex;
		$this->siteScopeStore = $siteScopeStore;
		$this->richAttributeDecorator = $richAttributeDecorator;
		$this->projectRoot = $projectRoot;
		$this->enabled = $enabled;
		$this->narrowingEnabled = $narrowingEnabled;
		$this->discoveryRefResolver = $discoveryRefResolver;
		$this->factoryProvidedVars = $factoryProvidedVars;
		$this->providerAvailabilityChecker = $providerAvailabilityChecker;
		$this->bootstrapFilesLoader = $bootstrapFilesLoader;
	}

	/**
	 * @return array<Stmt>
	 */
	public function parseFile(string $file): array
	{
		if (substr_compare($file, '.latte', -6) === 0) {
			if (!$this->enabled) {
				return [];
			}

			if (array_key_exists($file, $this->parsedFiles)) {
				return $this->parsedFiles[$file];
			}

			if (array_key_exists($file, $this->parsingFiles)) {
				$inProgressPhpSource = $this->parsingFiles[$file];

				return $inProgressPhpSource === null
					? []
					: $this->delegate->parseString($inProgressPhpSource);
			}

			$this->parsingFiles[$file] = null;

			try {
				return $this->parsedFiles[$file] = $this->parseLatteFile($file);
			} finally {
				unset($this->parsingFiles[$file]);
			}
		}

		return $this->delegate->parseFile($file);
	}

	/**
	 * @return array<Stmt>
	 */
	public function parseString(string $sourceCode): array
	{
		return $this->delegate->parseString($sourceCode);
	}

	/**
	 * @return array<Stmt>
	 */
	private function parseLatteFile(string $file): array
	{
		if ($this->bootstrapFilesLoader !== null) {
			$this->bootstrapFilesLoader->load();
		}

		try {
			$source = FileSystem::read($file);
		} catch (IOException $e) {
			// A file that vanished (or became unreadable) mid-run shouldn't crash the whole
			// analysis; PHPStan itself already tolerates files disappearing between listing and
			// parsing. Degrade to an empty AST, same as the disabled/non-latte-extension case.
			return [];
		}

		$relativePath = ProjectRelativePath::relativize($this->projectRoot, $file);
		$className = TemplateClassName::forPath($relativePath);

		$compiled = $this->adapter->compile($source, $className);
		// Arms the re-entrancy guard's answer as early as there is one to give: everything below this
		// line can reach the ReflectionProvider and so can be re-entered for this same file.
		$this->parsingFiles[$file] = $compiled->getPhpSource();
		$declarations = $this->scanner->scan($source);
		// Shares declarationsFor()'s own memo: a file already parsed as a top-level analysis
		// target is never re-read+re-scanned again just because some OTHER file's
		// reachableTemplateTypeClasses() walk reaches it too.
		$this->declarationsCache[$relativePath] = $declarations;
		$contexts = $this->contextResolver->contextsFor($relativePath);

		$stmts = $this->pipeline->process($compiled, $declarations, $contexts, $relativePath);
		$edgeDiagnostics = $this->contractChecker->check($relativePath, $contexts);
		$consistencyDiagnostics = $this->consistencyChecker->check($declarations, $file, $relativePath);
		$providerDiagnostics = $this->providerAvailabilityChecker !== null
			? $this->providerAvailabilityChecker->check($relativePath, $this->pipeline->getProviderMacroSites())
			: [];
		// Dependency-free, like DiagnosticMaterializer below: mid-file {varType} placement is a
		// property of this file's own token stream and nothing else.
		$placementDiagnostics = (new VarTypePlacementChecker())->check($declarations);
		$diagnostics = array_merge(
			$this->pipeline->getDiagnostics(),
			$edgeDiagnostics,
			$consistencyDiagnostics,
			$providerDiagnostics,
			$placementDiagnostics,
		);

		$materialized = (new DiagnosticMaterializer())->materialize($stmts, $diagnostics, $className);

		// A failed compile materializes a minimal stand-in class (DiagnosticMaterializer) with no
		// real analysis to protect - only a successful compile's class needs its cached result tied
		// to its neighbors.
		if ($compiled->getPhpSource() === null) {
			return $this->richAttributeDecorator->decorate($materialized);
		}

		$emitter = new DependencyEdgeEmitter();

		// Exported (public) class constant, value = sha1 over this file's OWN outgoing include
		// sites: a body-only edit to which args an {include} site passes (or which file/block it
		// targets) never changes this class's signature, so PHPStan's exportedNodesChanged() would
		// otherwise see no change and skip reanalysing files that depend on this one via the edges
		// below - the fingerprint's VALUE changing is what makes that edit visible to PHPStan's own
		// incremental engine.
		$facts = $this->edgeIndex->factsFor($file);
		// Opt-in gate: disabled narrowing never calls into the store at all - it presents the
		// exact hash a real, permanently-empty store would (SiteScopeStore::emptySliceHash()),
		// which is also what today's store-absent gate already produces,
		// so the fingerprint's own value never depends on whether a store directory happens to
		// exist on disk when the flag is off.
		$siteScopeSliceHash = $this->narrowingEnabled
			? $this->siteScopeStore->sliceHash($relativePath, sha1($source))
			: SiteScopeStore::emptySliceHash();
		// PHPStan's own result-cache propagation is single-hop from a file whose CONTENT HASH
		// genuinely changed (see EdgeFingerprint's class doc) - a body-only edit that only reaches
		// this file THROUGH an unedited intermediate .latte file never propagates further, so every
		// {templateType} class reachable ANYWHERE in this file's own transitive outgoing include
		// graph (not just its own declaration) needs a DIRECT edge here, not just one on the
		// declaring file.
		$reachableTemplateTypeClasses = $this->reachableTemplateTypeClasses($relativePath);
		// The factory-provided scope's own template classes ride that same channel: a property
		// ADDED TO or REMOVED FROM one of them changes which variables this file's scope carries and
		// with which types, and that edit is signature-level, so the ref reanalyses this template on
		// the same warm run and the fingerprint carries the change on to the files this one
		// includes. WHICH class a renderer resolves is a different question with a different,
		// store-mediated channel - see FactoryProvidedVars' own class doc.
		$scopeClasses = $this->scopeClasses($relativePath, $reachableTemplateTypeClasses);
		$withFingerprint = $emitter->emitFingerprint(
			$materialized,
			EdgeFingerprint::compute(
				$this->edgeIndex->outgoingSites($relativePath),
				$facts->getTopLevelVars(),
				$facts->getTopLevelDefaults(),
				$siteScopeSliceHash,
				$this->templateTypeVars($scopeClasses),
			),
		);

		$emitted = $emitter->emit($withFingerprint, $this->neighborRelPaths($relativePath));
		$emitted = $emitter->emitSliceRefs(
			$emitted,
			$this->narrowingEnabled ? $this->incomingIncluderRelsWithSlice($relativePath) : [],
		);
		$emitted = $emitter->emitTemplateTypeRef($emitted, $scopeClasses);
		// Discovery-store edges (single-hop, DIRECT): the template's own store class plus every
		// record's renderer + read-set classes - the resolver's own enabled flag gates this to [].
		if ($this->discoveryRefResolver !== null) {
			$emitted = $emitter->emitDiscoveryRefs(
				$emitted,
				$this->discoveryRefResolver->refClassNamesFor($relativePath),
			);
		}

		return $this->richAttributeDecorator->decorate($emitted);
	}

	// EdgeFingerprint's own transitive-includer window (see its class doc): a reflection failure
	// degrades to no vars folded in for that one class, same as DeclaredVarsResolver::resolve()'s
	// own precedent for the identical call - orisaiNette.latte.unknownType already reports a bad templateType
	// once, at ITS declaring file, so silently contributing nothing here never hides that
	// diagnostic. Later classes in $templateTypeClasses win on a property-name collision (matches
	// PropertyTypeResolver::resolveAllPublic()'s own per-class last-property-wins iteration order,
	// applied one level up across classes instead of within one).

	/**
	 * @param list<string> $templateTypeClasses
	 * @return array<string, string>
	 */
	private function templateTypeVars(array $templateTypeClasses): array
	{
		$vars = [];
		foreach ($templateTypeClasses as $templateTypeClass) {
			if (!class_exists($templateTypeClass)) {
				continue;
			}

			try {
				foreach (PropertyTypeResolver::resolveAllPublic($templateTypeClass) as $name => $type) {
					$vars[$name] = $type;
				}
			} catch (ReflectionException $e) {
				continue;
			}
		}

		return $vars;
	}

	// Deduplicated union, factory-provided classes FIRST (they arrive sorted), reachable
	// {templateType} classes after them: templateTypeVars() lets later classes win a property-name
	// collision, so a declared {templateType} keeps overriding the factory's own property type,
	// never the other way round - the priority ContextResolver's own overlay order already gives it.

	/**
	 * @param list<string> $reachableTemplateTypeClasses
	 * @return list<string>
	 */
	private function scopeClasses(string $relativePath, array $reachableTemplateTypeClasses): array
	{
		if ($this->factoryProvidedVars === null) {
			return $reachableTemplateTypeClasses;
		}

		$classes = [];
		foreach ($this->factoryProvidedVars->templateClassesFor($relativePath) as $className) {
			$classes[$className] = true;
		}

		foreach ($reachableTemplateTypeClasses as $className) {
			$classes[$className] = true;
		}

		return array_keys($classes);
	}

	// Every {templateType} class reachable ANYWHERE in $relativePath's own transitive outgoing
	// include graph (STATIC, resolved, existing targets only - a dynamic/unresolved/missing site
	// contributes nothing, same domain neighborRelPaths()/EdgeFingerprint already restrict
	// themselves to), not just its own direct declaration - see the class-doc comment at this
	// method's own call site for why single-hop propagation makes this necessary.

	/**
	 * @return list<string>
	 */
	private function reachableTemplateTypeClasses(string $relativePath): array
	{
		$visited = [];
		$classes = [];
		$this->collectTemplateTypeClasses($relativePath, $visited, $classes);

		return array_keys($classes);
	}

	/**
	 * @param array<string, true> $visited
	 * @param array<string, true> $classes
	 */
	private function collectTemplateTypeClasses(string $relativePath, array &$visited, array &$classes): void
	{
		if (isset($visited[$relativePath])) {
			return;
		}

		$visited[$relativePath] = true;

		$declarations = $this->declarationsFor($relativePath);
		$templateTypeClass = $declarations !== null ? $declarations->getTemplateTypeClass() : null;
		if ($templateTypeClass !== null) {
			$classes[$templateTypeClass] = true;
		}

		foreach ($this->edgeIndex->outgoingSites($relativePath) as $site) {
			$resolvedPath = $site->getResolvedPath();
			if ($resolvedPath === null || !$this->edgeIndex->targetExists($site)) {
				continue;
			}

			$this->collectTemplateTypeClasses($resolvedPath, $visited, $classes);
		}
	}

	private function declarationsFor(string $relativePath): ?Declarations
	{
		if (array_key_exists($relativePath, $this->declarationsCache)) {
			return $this->declarationsCache[$relativePath];
		}

		try {
			$source = FileSystem::read($this->projectRoot . '/' . $relativePath);
		} catch (IOException $e) {
			return $this->declarationsCache[$relativePath] = null;
		}

		return $this->declarationsCache[$relativePath] = $this->scanner->scan($source);
	}

	/**
	 * @return list<string>
	 */
	private function neighborRelPaths(string $relativePath): array
	{
		$neighbors = [];

		foreach ($this->edgeIndex->incomingEdges($relativePath) as $edge) {
			// A discovery marker's includer is a renderer CLASS NAME; TemplateClassName::forPath()
			// would mint a LatteTpl_* identifier for a template that does not exist. That class edge
			// is emitted separately (emitDiscoveryRefs, off DiscoveryRefResolver) and belongs there.
			if ($edge['site']->getKind() === IncludeTarget::KIND_DISCOVERY) {
				continue;
			}

			$neighbors[$edge['includer']] = true;
		}

		foreach ($this->edgeIndex->outgoingSites($relativePath) as $site) {
			$resolvedPath = $site->getResolvedPath();
			if ($resolvedPath !== null && $this->edgeIndex->targetExists($site)) {
				$neighbors[$resolvedPath] = true;
			}
		}

		// The ancestor half of an auto-layout edge: this template reads its layout's blocks
		// (reachableBlockOrigins()), so an edit there must reanalyze this file too.
		foreach ($this->edgeIndex->autoLayoutAncestors($relativePath) as $layoutRel) {
			$neighbors[$layoutRel] = true;
		}

		$relPaths = array_keys($neighbors);
		sort($relPaths, SORT_STRING);

		return $relPaths;
	}

	// This file is a TARGET of each of its incoming includers - a changed slice belonging to one
	// of them means captures ContextResolver overlaid into THIS file's contexts may have changed.
	// Only includers whose slice already exists on disk qualify -
	// SiteScopeStore::hasSlice() - so this never references a class.notFound slice class.

	/**
	 * @return list<string>
	 */
	private function incomingIncluderRelsWithSlice(string $relativePath): array
	{
		$rels = [];

		foreach ($this->edgeIndex->incomingEdges($relativePath) as $edge) {
			if ($edge['site']->getKind() === IncludeTarget::KIND_DISCOVERY) {
				continue;
			}

			$includerRel = $edge['includer'];
			if ($this->siteScopeStore->hasSlice($includerRel)) {
				$rels[$includerRel] = true;
			}
		}

		$relPaths = array_keys($rels);
		sort($relPaths, SORT_STRING);

		return $relPaths;
	}

}
