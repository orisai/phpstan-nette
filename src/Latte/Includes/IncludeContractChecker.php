<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use PHPStan\PhpDoc\TypeStringResolver;
use Throwable;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function basename;
use function count;
use function file_exists;
use function in_array;
use function sprintf;

final class IncludeContractChecker
{

	private const DYNAMIC_EXTENDS_TAGS = ['extends', 'layout'];

	// {include #block}/{include block} and {embed #block}/{embed block} compile to the same
	// renderBlock() dispatch (BlockMacros::macroInclude/macroEmbed) and are checked identically
	// here - the file-form of {embed 'file.latte'} never reaches this method (its kind is
	// KIND_STATIC_FILE, routed through checkDeclaredVars() instead).
	private const STATIC_BLOCK_TAGS = ['include', 'embed'];

	private TemplateEdgeIndex $index;

	private LatteUniverse $universe;

	private ContextResolver $contextResolver;

	private TypeStringResolver $typeStringResolver;

	private ArgTyper $argTyper;

	private DeclaredVarsResolver $declaredVarsResolver;

	private CapturedOverlay $capturedOverlay;

	private bool $includeIsolation;

	/** @var array<string, string>|null */
	private ?array $relativeToAbsolute = null;

	public function __construct(
		TemplateEdgeIndex $index,
		LatteUniverse $universe,
		ContextResolver $contextResolver,
		TypeStringResolver $typeStringResolver,
		CapturedOverlay $capturedOverlay,
		bool $includeIsolation = false
	)
	{
		$this->index = $index;
		$this->universe = $universe;
		$this->contextResolver = $contextResolver;
		$this->typeStringResolver = $typeStringResolver;
		$this->argTyper = new ArgTyper();
		$this->declaredVarsResolver = new DeclaredVarsResolver($index);
		$this->capturedOverlay = $capturedOverlay;
		$this->includeIsolation = $includeIsolation;
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return list<Diagnostic>
	 */
	public function check(string $projectRelativePath, array $contexts): array
	{
		$diagnostics = [];

		foreach ($this->index->outgoingSites($projectRelativePath) as $site) {
			foreach ($this->checkSite($projectRelativePath, $site, $contexts) as $diagnostic) {
				$diagnostics[] = $diagnostic;
			}
		}

		// cutCycleEdges() excludes depth-cap cuts by construction (see ContextResolver::
		// depthCapCuts()) - a >DEPTH_CAP-deep non-cyclic chain's cut is DFS-order-dependent, so
		// reporting it here would make orisaiNette.latte.includeCycle worker/traversal-order-dependent.
		foreach ($this->contextResolver->cutCycleEdges() as $cut) {
			if ($cut['includer'] !== $projectRelativePath) {
				continue;
			}

			$diagnostics[] = new Diagnostic(
				'orisaiNette.latte.includeCycle',
				sprintf(
					"Include cycle detected: '%s' includes '%s', which cycles back to an includer already being resolved.",
					$projectRelativePath,
					$cut['target'],
				),
				$cut['latteLine'],
			);
		}

		return $diagnostics;
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return list<Diagnostic>
	 */
	private function checkSite(string $projectRelativePath, IncludeTarget $site, array $contexts): array
	{
		if ($site->getKind() === IncludeTarget::KIND_DYNAMIC) {
			return [$this->dynamicDiagnostic($site)];
		}

		if ($site->getKind() === IncludeTarget::KIND_STATIC_BLOCK) {
			return array_merge(
				$this->checkStaticBlock($projectRelativePath, $site),
				$this->checkBlockDeclaredVars($projectRelativePath, $site, $contexts),
			);
		}

		if (!$this->index->targetExists($site)) {
			return [
				new Diagnostic(
					'orisaiNette.latte.unknownInclude',
					$this->unknownIncludeMessage($site),
					$site->getLatteLine(),
				),
			];
		}

		return $this->checkDeclaredVars($projectRelativePath, $site, $contexts);
	}

	// targetExists() only answers "in the analysed universe" (paths:) - a target can legitimately
	// exist on disk (e.g. a vendor/ template) while sitting outside it. The filesystem check below is
	// itself the input for this diagnostic's wording; deterministic per run since filesystem state
	// doesn't change mid-analysis.
	private function unknownIncludeMessage(IncludeTarget $site): string
	{
		$resolvedPath = (string) $site->getResolvedPath();
		$absolute = $this->universe->projectRoot() . '/' . $resolvedPath;

		if (file_exists($absolute)) {
			return sprintf("Include target '%s' exists but is outside the analysed paths.", $resolvedPath);
		}

		return sprintf("Include target '%s' does not exist.", $resolvedPath);
	}

	private function dynamicDiagnostic(IncludeTarget $site): Diagnostic
	{
		$isExtends = in_array($site->getTag(), self::DYNAMIC_EXTENDS_TAGS, true);

		return new Diagnostic(
			$isExtends ? 'orisaiNette.latte.dynamicExtends' : 'orisaiNette.latte.dynamicInclude',
			'Include target cannot be determined statically.',
			$site->getLatteLine(),
		);
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function checkStaticBlock(string $projectRelativePath, IncludeTarget $site): array
	{
		if (
			!in_array($site->getTag(), self::STATIC_BLOCK_TAGS, true)
			|| in_array($site->getRawTarget(), $this->index->reachableBlockNames($projectRelativePath), true)
		) {
			return [];
		}

		// Zero incoming edges OR `@`-prefixed basename: an unreachable block here is unknowable,
		// not definite (OPEN, never false-close). Discovery edges close neither half - the store
		// links only templates whose renderer the walk could resolve, so a template no edge names
		// may still be rendered through wiring it could not chase, and a layout's extender set -
		// which is what contributes the layout's declared blocks - is provably incomplete, leaving
		// any slot declared behind an unlinked extender falsely unreachable. Pinned by
		// DiscoveryEdgeIngestionTest::testAtPrefixedLayoutKeepsItsValveDespiteIncomingEdges.
		$basename = basename($projectRelativePath);
		if ($this->index->incomingEdges($projectRelativePath) === [] || $basename[0] === '@') {
			return [];
		}

		return [
			new Diagnostic(
				'orisaiNette.latte.unknownBlock',
				sprintf(
					"Block '%s' is not reachable from '%s'.",
					$site->getRawTarget(),
					$projectRelativePath,
				),
				$site->getLatteLine(),
			),
		];
	}

	// Block-edge counterpart of checkDeclaredVars() below: same per-context provided-scope
	// computation and the same checkDeclaredVar()/checkMismatch() definite-NO/all-contexts
	// machinery, only the CONTRACT (declared vars) and the target's identity resolve differently
	// - a block target has no resolvedPath (see IncludeTarget::KIND_STATIC_BLOCK), so it must be
	// found by name through the block graph instead. checkStaticBlock() only checks REACHABILITY
	// (does the name exist at all); this checks the found block's own INPUT CONTRACT, so the two
	// run independently and their diagnostics are merged by the caller.

	/**
	 * @param list<TemplateContext> $contexts
	 * @return list<Diagnostic>
	 */
	private function checkBlockDeclaredVars(string $projectRelativePath, IncludeTarget $site, array $contexts): array
	{
		$blockName = $site->getRawTarget();

		// A block name reachable through more than one distinct defining file (layout-chain
		// ancestors, extender overrides, diamond imports) has no single contract to check
		// against - never guess which one applies (OPEN, the same "ambiguous target" discipline
		// checkDeclaredVar's own per-context valve already applies one dimension over).
		$origins = $this->index->reachableBlockOrigins($projectRelativePath)[$blockName] ?? [];
		if (count($origins) !== 1) {
			return [];
		}

		$targetRel = $origins[0];
		$targetAbsolute = $this->absoluteFor($targetRel);
		if ($targetAbsolute === null) {
			return [];
		}

		$resolved = $this->blockContract($targetAbsolute, $blockName, $site, $targetRel === $projectRelativePath);
		if ($resolved['contract'] === []) {
			return [];
		}

		$targetLabel = $targetRel . '#' . $blockName;

		$diagnostics = [];
		foreach ($resolved['contract'] as $name => $declaredType) {
			// Reuses checkDeclaredVar()'s existing $defaultNames exemption vehicle: for a
			// same-file param-less block, a body-varType name absent from EdgeScope's own
			// approximation might still genuinely arrive via get_defined_vars() (ambient reach
			// this checker can't see), so the MISSING report alone is suppressed for it here -
			// checkMismatch() is never gated by this, so an explicitly-visible wrong-typed value
			// still reports (same visibility channel cross-file blocks use).
			$missingExempt = in_array($name, $resolved['missingExempt'], true) ? [$name] : [];

			$diagnostics = array_merge(
				$diagnostics,
				$this->checkDeclaredVar(
					$projectRelativePath,
					$site,
					$contexts,
					$name,
					$declaredType,
					$missingExempt,
					$targetLabel,
					$resolved['ownParamNames'],
				),
			);
		}

		return $diagnostics;
	}

	// Block contract = own {define}/{block} params without a default, union the block body's own
	// depth-0 {varType}s (DeclaredVarsResolver::forBlock() - never the file's own top-level
	// declarations, and never an outer block's, even when nested). A name declared both ways is
	// settled by the varType, applied last: the more specific local override wins.

	/**
	 * @return array{contract: array<string, string>, missingExempt: list<string>, ownParamNames: list<string>}
	 */
	private function blockContract(string $absoluteFile, string $blockName, IncludeTarget $site, bool $sameFile): array
	{
		$declarations = $this->index->declarationsFor($absoluteFile);
		$ownParams = $declarations->getDefineParams()[$blockName] ?? [];
		$paramDefaults = $declarations->getDefineParamDefaults()[$blockName] ?? [];

		$paramContract = [];
		$ownParamNames = [];
		foreach ($ownParams as [$type, $name]) {
			$ownParamNames[] = $name;
			if (isset($paramDefaults[$name])) {
				continue;
			}

			$paramContract[$name] = $type ?? 'mixed';
		}

		$bodyVarTypes = $this->declaredVarsResolver->forBlock($absoluteFile, $blockName);
		$contract = array_merge($paramContract, $bodyVarTypes);

		// A block's own params bind POSITIONALLY at the call site (BlockDispatchEliminator's own
		// direct-call rewrite matches by position); ArgTyper only types `name: expr`/`name =>
		// expr` pairs, so a bare positional argument for one of these names is invisible to the
		// provided-scope computation below. Trusting an unrelated same-named ambient value in
		// that case would risk a false report against a variable the block never actually
		// receives this way (a declared param always shadows ambient reach for its own name) -
		// never guess, drop the own-param names from this edge's MERGED contract entirely
		// instead. Applying the drop before the merge is not enough: an own param that also
		// carries a body {varType} (the untyped-own-param + body-varType pairing this feature
		// blesses) would have its name re-added by the merge above, undoing the drop.
		if ($paramContract !== [] && $this->argTyper->hasPositionalArgs($site)) {
			foreach (array_keys($paramContract) as $name) {
				unset($contract[$name]);
			}
		}

		// Same-file, PARAM-LESS dispatch also reaches the block via real Latte's universal
		// get_defined_vars()+extract($ʟ_args) prolog - a body-varType name absent from EdgeScope's
		// own approximation (the includer's formally-declared vars only, never ambient
		// loop/conditional locals) might still genuinely arrive this way, so only the MISSING
		// report is exempted for it below; checkMismatch() stays unconditional (an own param never
		// gets this fallback at all, so it's never exempted either).
		$missingExempt = $sameFile && $ownParams === [] ? array_keys($bodyVarTypes) : [];

		return ['contract' => $contract, 'missingExempt' => $missingExempt, 'ownParamNames' => $ownParamNames];
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return list<Diagnostic>
	 */
	private function checkDeclaredVars(string $projectRelativePath, IncludeTarget $site, array $contexts): array
	{
		$resolvedPath = $site->getResolvedPath();
		if ($resolvedPath === null) {
			return [];
		}

		$targetAbsolute = $this->absoluteFor($resolvedPath);
		if ($targetAbsolute === null) {
			return [];
		}

		$declaredVars = $this->declaredVarsResolver->forFile($targetAbsolute);
		if ($declaredVars === []) {
			return [];
		}

		$defaultNames = $this->defaultNamesFor($targetAbsolute);

		$diagnostics = [];
		foreach ($declaredVars as $name => $declaredType) {
			$diagnostics = array_merge(
				$diagnostics,
				$this->checkDeclaredVar(
					$projectRelativePath,
					$site,
					$contexts,
					$name,
					$declaredType,
					$defaultNames,
					$resolvedPath,
				),
			);
		}

		return $diagnostics;
	}

	// F4 (carried from declaration consistency): the ONLY caller passing a non-empty
	// $ownParamNames is checkBlockDeclaredVars() - a file target has no own-param concept at all,
	// so checkDeclaredVars() below always leaves this at its default (no gate applied). EdgeScope
	// itself stays param-unaware by design (its own drift-warning comment is the single source of
	// truth for what an edge nominally provides, shared verbatim with ContextResolver, which never
	// even sees block-target sites - TemplateEdgeIndex only populates incoming edges for
	// KIND_STATIC_FILE); the own-param restriction is knowledge only this checker has (via
	// blockContract()'s own DeclarationScanner read), so it is applied here, as a strictly
	// narrowing filter on EdgeScope's already-computed result, never inside EdgeScope.

	/**
	 * @param list<TemplateContext> $contexts
	 * @param list<string> $defaultNames
	 * @param list<string> $ownParamNames
	 * @return list<Diagnostic>
	 */
	private function checkDeclaredVar(
		string $projectRelativePath,
		IncludeTarget $site,
		array $contexts,
		string $name,
		string $declaredType,
		array $defaultNames,
		string $targetPath,
		array $ownParamNames = []
	): array
	{
		$includerAbsolute = $this->absoluteFor($projectRelativePath);

		$providedTypes = [];
		foreach ($contexts as $context) {
			$scope = EdgeScope::resolve(
				$site,
				$context,
				$this->argTyper,
				fn (): array => $this->includerTopLevelVars($projectRelativePath),
				$this->includeIsolation,
			);

			$scopeVars = $scope['vars'];
			$namedKeys = $scope['namedKeys'];

			// BlockMacros::extractMethod drops the universal extract($ʟ_args) fallback the moment a
			// block declares even one own param (CrossFileScopeParityTest::
			// testOwnParamBlockNeverExtractsUnmatchedExtraArg) - a named arg that matches none of
			// the target's own params is a genuine extra there and must not count as provided.
			// Falls back to the ambient (context-inherited) value for the same name when one
			// exists, rather than deleting it outright: that ambient contribution is a separate,
			// already-established mechanism this fix must not touch.
			if ($ownParamNames !== []) {
				$ambientVars = $context->getVars();
				foreach ($namedKeys as $extraName => $extraType) {
					if (in_array($extraName, $ownParamNames, true)) {
						continue;
					}

					if (array_key_exists($extraName, $ambientVars)) {
						$scopeVars[$extraName] = $ambientVars[$extraName];
					} else {
						unset($scopeVars[$extraName]);
					}

					unset($namedKeys[$extraName]);
				}
			}

			// Overlaid (narrowed) provided types feed this definite-NO check below. A narrowed type
			// is always a subset of the wide one EdgeScope alone would give, so checkMismatch()'s
			// isSuperTypeOf()->no() can only ever gain new definite mismatches here, never lose a
			// real one or manufacture a false one - narrowing an already-disjoint type keeps it
			// disjoint, and narrowing a partially-overlapping (maybe) type can only shrink it toward
			// or into full disjointness, never the reverse.
			$vars = $scopeVars;
			if ($includerAbsolute !== null) {
				$overlaid = $this->capturedOverlay->overlay(
					$scopeVars,
					$namedKeys,
					$projectRelativePath,
					$includerAbsolute,
					$site->getLatteLine(),
					$site->getRawTarget(),
					$context->canonicalHash(),
				);
				$vars = $overlaid['vars'];
			}

			if (array_key_exists($name, $vars)) {
				$providedTypes[] = $vars[$name];
			}
		}

		if ($providedTypes === []) {
			if (in_array($name, $defaultNames, true)) {
				return [];
			}

			return [
				new Diagnostic(
					'orisaiNette.latte.includeMissingVariable',
					sprintf(
						"Include target '%s' requires variable \$%s (%s) that is not provided and has no default.",
						$targetPath,
						$name,
						$declaredType,
					),
					$site->getLatteLine(),
				),
			];
		}

		// Provided in some contexts of this includer but not others: which one is "correct"
		// depends on the caller path, so stay silent rather than guess (OPEN).
		if (count($providedTypes) < count($contexts)) {
			return [];
		}

		return $this->checkMismatch($site, $name, $declaredType, $providedTypes, $targetPath);
	}

	/**
	 * @param list<string> $providedTypes
	 * @return list<Diagnostic>
	 */
	private function checkMismatch(
		IncludeTarget $site,
		string $name,
		string $declaredType,
		array $providedTypes,
		string $targetPath
	): array
	{
		try {
			$declared = $this->typeStringResolver->resolve($declaredType);
		} catch (Throwable $e) {
			return [];
		}

		foreach ($providedTypes as $providedType) {
			try {
				$provided = $this->typeStringResolver->resolve($providedType);
			} catch (Throwable $e) {
				return [];
			}

			// Only a definite NO is reported; a maybe (e.g. a wider provided union that partially
			// overlaps the declared type) is ambiguous and stays silent (OPEN).
			if (!$declared->isSuperTypeOf($provided)->no()) {
				return [];
			}
		}

		return [
			new Diagnostic(
				'orisaiNette.latte.includeTypeMismatch',
				sprintf(
					"Variable \$%s provided as %s does not match declared type %s in '%s'.",
					$name,
					$providedTypes[0],
					$declaredType,
					$targetPath,
				),
				$site->getLatteLine(),
			),
		];
	}

	// Only top-level (unconditional) {default} names count: one nested inside {if}/{foreach}
	// doesn't guarantee the variable is actually set, so trusting it here would turn a real
	// missing-variable bug into a false negative.

	/**
	 * @return list<string>
	 */
	private function defaultNamesFor(string $absoluteFile): array
	{
		return $this->index->factsFor($absoluteFile)->getTopLevelDefaults();
	}

	/**
	 * @return array<string, string>
	 */
	private function includerTopLevelVars(string $projectRelativePath): array
	{
		$absolute = $this->absoluteFor($projectRelativePath);
		if ($absolute === null) {
			return [];
		}

		return $this->index->factsFor($absolute)->getTopLevelVars();
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

}
