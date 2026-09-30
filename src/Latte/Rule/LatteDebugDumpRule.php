<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\MutationFact;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingOpaque;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_string;
use function ltrim;
use function preg_match;
use function sort;
use function sprintf;
use function strtolower;
use function substr_compare;
use const SORT_STRING;

/**
 * @implements Rule<FuncCall>
 */
final class LatteDebugDumpRule implements Rule
{

	// Shared with LatteProvenanceTipRule, which excludes this identifier from tip decoration - a
	// debug dump is a diagnostic the developer wrote directly, never generated code needing its
	// origin explained.
	public const IDENTIFIER = 'orisai.nette.latte.debugDump';

	// Compiled Latte classes carry no `namespace` statement (LatteCompiler emits straight into the
	// global namespace), so the documented usage - the bare, unqualified {do
	// dumpLatteIncluders()} - never resolves to the OriPhpstan\Nette\Latte\Testing\... FQN at the AST
	// level; it stays the plain short name. Both forms are matched (case-insensitively, like every
	// other identifier this rule compares) - the short names are distinctive enough that a
	// collision with an unrelated global function of the same name is a non-issue.
	private const FN_INCLUDERS_FQN = 'oriphpstan\nette\latte\testing\dumplatteincluders';

	private const FN_INCLUDERS_SHORT = 'dumplatteincluders';

	private const FN_VAR_ORIGIN_FQN = 'oriphpstan\nette\latte\testing\dumplattevarorigin';

	private const FN_VAR_ORIGIN_SHORT = 'dumplattevarorigin';

	private const FN_CUSTOMS_FQN = 'oriphpstan\nette\latte\testing\dumplattecustoms';

	private const FN_CUSTOMS_SHORT = 'dumplattecustoms';

	private const FN_RENDER_FACTS_FQN = 'oriphpstan\nette\latte\testing\dumplatterenderfacts';

	private const FN_RENDER_FACTS_SHORT = 'dumplatterenderfacts';

	private const FN_PAIRING_FQN = 'oriphpstan\nette\latte\testing\dumplattepairing';

	private const FN_PAIRING_SHORT = 'dumplattepairing';

	private const FN_DISCOVERY_FQN = 'oriphpstan\nette\latte\testing\dumplattediscovery';

	private const FN_DISCOVERY_SHORT = 'dumplattediscovery';

	private ContextResolver $contextResolver;

	private TemplateEdgeIndex $edgeIndex;

	private LatteUniverse $universe;

	private ?CustomsHarvester $harvester;

	private ?TemplateTypeCustoms $templateTypeCustoms;

	private ?PhpRenderWalk $renderWalk;

	private ?PhpFactsCache $renderFactsCache;

	private ?PairingJudge $pairingJudge;

	private ?ReflectionProvider $reflectionProvider;

	public function __construct(
		ConfigurationGuard $guard,
		ContextResolver $contextResolver,
		TemplateEdgeIndex $edgeIndex,
		LatteUniverse $universe,
		?CustomsHarvester $harvester = null,
		?TemplateTypeCustoms $templateTypeCustoms = null,
		?PhpRenderWalk $renderWalk = null,
		?PhpFactsCache $renderFactsCache = null,
		?PairingJudge $pairingJudge = null,
		?ReflectionProvider $reflectionProvider = null
	)
	{
		$guard->validate();
		$this->contextResolver = $contextResolver;
		$this->edgeIndex = $edgeIndex;
		$this->universe = $universe;
		$this->harvester = $harvester;
		$this->templateTypeCustoms = $templateTypeCustoms;
		$this->renderWalk = $renderWalk;
		$this->renderFactsCache = $renderFactsCache;
		$this->pairingJudge = $pairingJudge;
		$this->reflectionProvider = $reflectionProvider;
	}

	public function getNodeType(): string
	{
		return FuncCall::class;
	}

	/**
	 * @param FuncCall $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node->name instanceof Name || $node->isFirstClassCallable()) {
			return [];
		}

		$funcName = strtolower(ltrim($node->name->toString(), '\\'));

		// Render facts describe a PHP class, not the hosting template, so unlike the
		// template-centric dumps below this one fires from any analyzed context - matched before
		// the .latte scope gate.
		if ($funcName === self::FN_RENDER_FACTS_FQN || $funcName === self::FN_RENDER_FACTS_SHORT) {
			return [$this->dumpRenderFacts($node)];
		}

		// The pairing verdict describes a PHP class too - same any-context firing as render facts.
		if ($funcName === self::FN_PAIRING_FQN || $funcName === self::FN_PAIRING_SHORT) {
			return [$this->dumpPairing($node)];
		}

		// Discovery describes a PHP class as well - same any-context firing.
		if ($funcName === self::FN_DISCOVERY_FQN || $funcName === self::FN_DISCOVERY_SHORT) {
			return [$this->dumpDiscovery($node)];
		}

		if (substr_compare($scope->getFile(), '.latte', -6) !== 0) {
			return [];
		}

		if ($funcName === self::FN_INCLUDERS_FQN || $funcName === self::FN_INCLUDERS_SHORT) {
			return [$this->dumpIncluders($node, $scope)];
		}

		if (
			($funcName === self::FN_VAR_ORIGIN_FQN || $funcName === self::FN_VAR_ORIGIN_SHORT)
			&& count($node->getArgs()) >= 1
		) {
			return [$this->dumpVarOrigin($node, $scope)];
		}

		if ($funcName === self::FN_CUSTOMS_FQN || $funcName === self::FN_CUSTOMS_SHORT) {
			return [$this->dumpCustoms($node, $scope)];
		}

		return [];
	}

	private function dumpIncluders(FuncCall $node, Scope $scope): IdentifierRuleError
	{
		$file = $this->universe->relativePath($scope->getFile());
		$edges = $this->edgeIndex->incomingEdges($file);

		if ($edges === []) {
			return $this->error(
				'no incoming Latte edges (convention-wired? PHP-side wiring is invisible until phase 3)',
				$node,
			);
		}

		$lines = [];
		foreach ($edges as $edge) {
			$includerRel = $edge['includer'];
			$site = $edge['site'];
			$contextCount = count($this->contextResolver->contextsFor($includerRel));
			$lines[] = sprintf(
				'%s:%d (%s) - %d context(s)',
				$includerRel,
				$site->getLatteLine(),
				$site->getTag(),
				$contextCount,
			);
		}

		return $this->error(implode("\n", $lines), $node);
	}

	private function dumpVarOrigin(FuncCall $node, Scope $scope): IdentifierRuleError
	{
		$argExpr = $node->getArgs()[0]->value;
		$name = $argExpr instanceof Variable && is_string($argExpr->name) ? $argExpr->name : null;
		$effectiveType = $scope->getType($argExpr)->describe(VerbosityLevel::precise());

		$file = $this->universe->relativePath($scope->getFile());
		$contexts = TemplateContext::sortByHash($this->contextResolver->contextsFor($file));
		$context = $this->contextForScope($scope, $contexts);

		$exprLabel = $name !== null ? '$' . $name : 'expr';
		$chainText = $this->describeChain($context);
		$provenanceText = $name !== null && $context !== null
			? ($context->getProvenance()[$name] ?? 'default:mixed')
			: 'unknown';
		$unionText = $name !== null
			? $this->describeUnion($contexts, $name)
			: $effectiveType . ' across 1 context';

		$message = sprintf(
			"%s: %s\nchain: %s\nprovenance: %s\nunion: %s",
			$exprLabel,
			$effectiveType,
			$chainText,
			$provenanceText,
			$unionText,
		);

		return $this->error($message, $node);
	}

	private function dumpCustoms(FuncCall $node, Scope $scope): IdentifierRuleError
	{
		$harvested = $this->harvester !== null ? $this->harvester->harvest() : HarvestedCustoms::empty();

		$lines = [];
		if ($this->harvester !== null && $this->harvester->hasConfiguredSource()) {
			$lines[] = 'global filters: ' . $this->describeHarvestEntries(
				$harvested->getFilters(),
				$harvested->getFilterOriginalNames(),
			);
			if ($harvested->getFilterLoaders() !== null) {
				$lines[] = 'loader filters: ' . $this->describeNames(array_keys($harvested->getLoaderFilters()));
			}

			$lines[] = 'global functions: ' . $this->describeHarvestEntries(
				$harvested->getFunctions(),
				$harvested->getFunctionOriginalNames(),
			);
			$lines[] = 'global macros: ' . $this->describeNames($harvested->getMacroNames());
			foreach ($harvested->getSourceNotes() as $note) {
				$lines[] = 'harvest note: ' . $note;
			}

			foreach ($harvested->getSourceProblems() as $problem) {
				$lines[] = 'harvest problem: ' . $problem;
			}
		} else {
			$lines[] = 'global: no harvest source configured (orisai.nette.dic.containerLoader / orisai.nette.latte.engineLoader)';
		}

		$templateTypeClass = $this->templateTypeClassFor($scope->getFile());
		$filtersFor = $this->templateTypeCustoms !== null
			? $this->templateTypeCustoms->filtersFor($templateTypeClass)
			: [];
		$functionsFor = $this->templateTypeCustoms !== null
			? $this->templateTypeCustoms->functionsFor($templateTypeClass)
			: [];

		$lines[] = 'template: ' . ($templateTypeClass ?? '(none)');
		$lines[] = 'template filters: ' . $this->describeTemplateEntries($filtersFor);
		$lines[] = 'template functions: ' . $this->describeTemplateEntries($functionsFor);

		return $this->error(implode("\n", $lines), $node);
	}

	private function dumpRenderFacts(FuncCall $node): IdentifierRuleError
	{
		$className = $this->classConstantArgument($node);
		if ($className === null) {
			return $this->error('dumpLatteRenderFacts() expects a ::class constant argument', $node);
		}

		$walk = $this->renderWalk;
		if ($walk === null) {
			return $this->error("class: {$className}\nno render facts: PhpRenderWalk not wired", $node);
		}

		$facts = $this->renderFactsCache !== null
			? $this->renderFactsCache->remember(
				$className,
				static fn (): PhpRenderFacts => $walk->factsFor($className),
			)
			: $walk->factsFor($className);

		if (!Qualification::qualifies($facts)) {
			return $this->error(
				"class: {$className}\nno render facts: " . $this->describeFactlessClass($className, $facts),
				$node,
			);
		}

		$templateClass = $facts->getTemplateClass();

		$lines = ['class: ' . $className];
		$lines[] = sprintf(
			'template class: %s (%s, %s)',
			$templateClass->getClassName(),
			$templateClass->getChannel(),
			$templateClass->getCertainty(),
		);

		if ($facts->getTemplateClassCandidates() !== []) {
			$lines[] = 'template class candidates:';
			foreach ($facts->getTemplateClassCandidates() as $candidate) {
				$sites = $candidate->getSites();
				$lines[] = sprintf(
					'%s (%s, %s)%s',
					$candidate->getClassName(),
					$candidate->getChannel(),
					$candidate->getCertainty(),
					$sites === [] ? '' : ' @ ' . implode(', ', $sites),
				);
			}
		}

		if ($facts->getAssignments() === []) {
			$lines[] = 'assignments: (none)';
		} else {
			$lines[] = 'assignments:';
			foreach ($facts->getAssignments() as $var => $assignment) {
				$lines[] = sprintf(
					'$%s: %s (%s) @ %s',
					$var,
					$assignment->getTypeString(),
					$assignment->getCertainty(),
					$this->describeSites($assignment->getSites()),
				);
			}
		}

		if ($facts->getSetFileTargets() === []) {
			$lines[] = 'setFile targets: (none)';
		} else {
			$lines[] = 'setFile targets:';
			foreach ($facts->getSetFileTargets() as $target) {
				$path = $target->getPath();
				$lines[] = sprintf(
					'%s (%s) @ %s',
					$path === null
						? $target->getKind()
						: $target->getKind() . " '" . $this->universe->relativePath($path) . "'",
					$target->getCertainty(),
					$this->describeSites([$target->getSite()]),
				);
			}
		}

		if ($facts->getRenderSites() === []) {
			$lines[] = 'render sites: (none)';
		} else {
			$lines[] = 'render sites:';
			foreach ($facts->getRenderSites() as $renderSite) {
				$site = $this->describeSites([$renderSite->getSite()]);
				$literalPath = $renderSite->getLiteralPath();
				if (!$renderSite->hasFileArg()) {
					$lines[] = $site . ' (no file arg)';
				} elseif ($literalPath === null) {
					$lines[] = $site . ' (file arg, non-literal)';
				} else {
					$lines[] = $site . " (file: '" . $this->universe->relativePath($literalPath) . "')";
				}
			}
		}

		return $this->error(implode("\n", $lines), $node);
	}

	private function dumpPairing(FuncCall $node): IdentifierRuleError
	{
		$className = $this->classConstantArgument($node);
		if ($className === null) {
			return $this->error('dumpLattePairing() expects a ::class constant argument', $node);
		}

		$walk = $this->renderWalk;
		if ($walk === null) {
			return $this->error("class: {$className}\nno pairing: PhpRenderWalk not wired", $node);
		}

		if ($this->pairingJudge === null) {
			return $this->error("class: {$className}\nno pairing: PairingJudge not wired", $node);
		}

		$facts = $this->renderFactsCache !== null
			? $this->renderFactsCache->remember(
				$className,
				static fn (): PhpRenderFacts => $walk->factsFor($className),
			)
			: $walk->factsFor($className);

		// The shared qualification gate: judge() throws on non-qualifying facts by contract.
		if (!Qualification::qualifies($facts)) {
			return $this->error(
				"class: {$className}\nno pairing: " . $this->describeFactlessClass($className, $facts),
				$node,
			);
		}

		$verdict = $this->pairingJudge->judge($facts);

		$lines = ['class: ' . $className];
		// A *dynamic* primary/candidate renders TemplateClassFact's never-a-class marker verbatim -
		// by construction not a valid class name, and the judge keeps it out of every conflict.
		$lines[] = sprintf(
			'primary: %s (%s, %s)',
			$verdict->getPrimaryClass(),
			$verdict->getPrimaryChannel(),
			$verdict->getPrimaryCertainty(),
		);

		if ($verdict->getCandidates() === []) {
			$lines[] = 'candidates: (none)';
		} else {
			$lines[] = 'candidates:';
			foreach ($verdict->getCandidates() as $candidate) {
				$sites = $candidate->getSites();
				$lines[] = sprintf(
					'%s (%s, %s)%s',
					$candidate->getClassName(),
					$candidate->getChannel(),
					$candidate->getCertainty(),
					$sites === [] ? '' : ' @ ' . implode(', ', $sites),
				);
			}
		}

		if ($verdict->getSitePairings() === []) {
			$lines[] = 'site pairings: (none)';
		} else {
			$lines[] = 'site pairings:';
			foreach ($verdict->getSitePairings() as $sitePairing) {
				$lines[] = sprintf(
					'%s (%s) @ %d',
					$sitePairing->getClassName(),
					$sitePairing->getChannel(),
					$sitePairing->getLine(),
				);
			}
		}

		$mergedConflicts = LattePairingRule::mergeConflicts($verdict->getConflicts());
		if ($mergedConflicts === []) {
			$lines[] = 'conflicts: (none)';
		} else {
			$lines[] = 'conflicts:';
			foreach ($mergedConflicts as $conflict) {
				$lines[] = sprintf(
					'%s (%s) vs %s (%s)',
					$conflict['declaredClass'],
					implode(', ', $conflict['declaredChannels']),
					$conflict['runtimeClass'],
					implode(', ', $conflict['runtimeChannels']),
				);
			}
		}

		if ($verdict->getOpaques() === []) {
			$lines[] = 'opaques: (none)';
		} else {
			$lines[] = 'opaques:';
			foreach ($verdict->getOpaques() as $opaque) {
				$lines[] = $opaque->getLine() === PairingOpaque::NO_SITE_LINE
					? $opaque->getChannel()
					: $opaque->getChannel() . ' @ ' . $opaque->getLine();
			}
		}

		return $this->error(implode("\n", $lines), $node);
	}

	private function dumpDiscovery(FuncCall $node): IdentifierRuleError
	{
		$className = $this->classConstantArgument($node);
		if ($className === null) {
			return $this->error('dumpLatteDiscovery() expects a ::class constant argument', $node);
		}

		$walk = $this->renderWalk;
		if ($walk === null) {
			return $this->error("class: {$className}\nno discovery: PhpRenderWalk not wired", $node);
		}

		$facts = $this->renderFactsCache !== null
			? $this->renderFactsCache->remember(
				$className,
				static fn (): PhpRenderFacts => $walk->factsFor($className),
			)
			: $walk->factsFor($className);

		if (!Qualification::qualifies($facts)) {
			return $this->error(
				"class: {$className}\nno discovery: " . $this->describeFactlessClass($className, $facts),
				$node,
			);
		}

		$discovery = $facts->getDiscovery();
		// Qualifying facts the walk itself produced always carry one; hand-built or restored facts
		// need not, and an all-empty dump would read as "discovery ran and found nothing".
		if ($discovery === null) {
			return $this->error("class: {$className}\nno discovery: facts carry no discovery fact", $node);
		}

		$lines = ['class: ' . $className];
		$lines[] = 'open view set: ' . ($facts->hasOpenViewSet() ? 'yes' : 'no');

		if ($facts->getViews() === []) {
			$lines[] = 'views: (none)';
		} else {
			$lines[] = 'views:';
			foreach ($facts->getViews() as $view) {
				$lines[] = sprintf(
					'%s (%s) @ %s from %s',
					$view->getName(),
					$view->getCertainty(),
					$this->describeSites($view->getSites()),
					self::describeViewSources($view->getSources()),
				);
			}
		}

		if ($discovery->getViewCandidates() === []) {
			$lines[] = 'view candidates: (none)';
		} else {
			$lines[] = 'view candidates:';
			foreach ($discovery->getViewCandidates() as $view => $candidates) {
				// A control (and a viewless presenter holding class-level writes) lands in the
				// empty-string bucket, which needs a name of its own to render.
				$label = (string) $view === '' ? '(no view)' : (string) $view;
				$lines[] = $candidates === [] ? $label . ': (none)' : $label . ':';
				foreach ($candidates as $candidate) {
					$lines[] = self::describeCandidate($candidate);
				}
			}
		}

		if ($discovery->getLayoutCandidates() === []) {
			$lines[] = 'layout candidates: (none)';
		} else {
			$lines[] = 'layout candidates:';
			foreach ($discovery->getLayoutCandidates() as $candidate) {
				$lines[] = self::describeCandidate($candidate);
			}
		}

		if ($discovery->getOpaques() === []) {
			$lines[] = 'opaques: (none)';
		} else {
			$lines[] = 'opaques:';
			foreach ($discovery->getOpaques() as $opaque) {
				$line = $opaque['line'];
				$lines[] = $line === null ? $opaque['reason'] : $opaque['reason'] . ' @ ' . $line;
			}
		}

		$ineffective = [];
		foreach ($facts->getMutations() as $mutation) {
			if ($mutation->getEffectiveness() !== MutationFact::EFFECTIVE_NO) {
				continue;
			}

			$ineffective[] = sprintf(
				'%s (%s) @ %d',
				$mutation->getKind(),
				$mutation->getPhase(),
				$mutation->getLine(),
			);
		}

		if ($ineffective === []) {
			$lines[] = 'ineffective mutations: (none)';
		} else {
			$lines[] = 'ineffective mutations:';
			foreach ($ineffective as $entry) {
				$lines[] = $entry;
			}
		}

		return $this->error(implode("\n", $lines), $node);
	}

	/**
	 * @param list<MutationFact|string> $sources
	 */
	private static function describeViewSources(array $sources): string
	{
		$parts = [];
		foreach ($sources as $source) {
			$parts[] = is_string($source) ? $source : $source->getKind() . ':' . $source->getLine();
		}

		return implode(', ', $parts);
	}

	private static function describeCandidate(CandidatePath $candidate): string
	{
		return sprintf(
			'%s (%s, %s%s)',
			$candidate->getPath(),
			$candidate->getKind(),
			$candidate->exists() ? 'exists' : 'missing',
			$candidate->isChosen() ? ', chosen' : '',
		);
	}

	// The m6-corrected texts: distinguishing "unknown" from "known but built-in/no-file" needs a
	// reflection probe the walk's empty facts cannot carry; without the provider the read-set is
	// the only (coarser) signal, collapsing both into "class not found".
	private function describeFactlessClass(string $className, PhpRenderFacts $facts): string
	{
		$nonQualifying = 'class does not qualify'
			. ' (no template surface or trusted createTemplate call under the app root)';

		if ($this->reflectionProvider === null) {
			return $facts->getReadSet() === [] ? 'class not found' : $nonQualifying;
		}

		if (!$this->reflectionProvider->hasClass($className)) {
			return 'class not found';
		}

		if ($this->reflectionProvider->getClass($className)->getFileName() === null) {
			return 'class has no analyzable file';
		}

		return $nonQualifying;
	}

	private function classConstantArgument(FuncCall $node): ?string
	{
		$args = $node->getArgs();
		if ($args === []) {
			return null;
		}

		$expr = $args[0]->value;
		if (
			!$expr instanceof ClassConstFetch
			|| !$expr->class instanceof Name
			|| !$expr->name instanceof Identifier
			|| strtolower($expr->name->toString()) !== 'class'
		) {
			return null;
		}

		return ltrim($expr->class->toString(), '\\');
	}

	/**
	 * @param list<array{file: string, line: int}> $sites
	 */
	private function describeSites(array $sites): string
	{
		return implode(', ', array_map(
			fn (array $site): string => $this->universe->relativePath($site['file']) . ':' . $site['line'],
			$sites,
		));
	}

	private function templateTypeClassFor(string $file): ?string
	{
		return $this->edgeIndex->declarationsFor($file)->getTemplateTypeClass();
	}

	/**
	 * @param array<string, callable(mixed...): mixed> $entries
	 * @param array<string, string> $originalNames
	 */
	private function describeHarvestEntries(array $entries, array $originalNames): string
	{
		if ($entries === []) {
			return '(none)';
		}

		$names = array_keys($entries);
		sort($names, SORT_STRING);

		$parts = [];
		foreach ($names as $name) {
			$orig = $originalNames[strtolower($name)] ?? null;
			$parts[] = $orig !== null && $orig !== $name ? sprintf('%s (%s)', $name, $orig) : $name;
		}

		return implode(', ', $parts);
	}

	/**
	 * @param list<string> $names
	 */
	private function describeNames(array $names): string
	{
		if ($names === []) {
			return '(none)';
		}

		$sorted = $names;
		sort($sorted, SORT_STRING);

		return implode(', ', $sorted);
	}

	/**
	 * @param array<string, array{string, string, bool, bool}> $entries
	 */
	private function describeTemplateEntries(array $entries): string
	{
		if ($entries === []) {
			return '(none)';
		}

		$parts = [];
		foreach ($entries as $entry) {
			[$declaringClass, $name] = $entry;
			$parts[] = sprintf('%s (%s)', $name, $declaringClass);
		}

		sort($parts, SORT_STRING);

		return implode(', ', $parts);
	}

	// Maps the current scope back to the context that produced it: DeclarationInjector clones
	// main() once per context (TemplateContext::sortByHash order), naming each clone
	// latteMain_ctx<i> - a scope whose function name doesn't match that pattern (main/prepare/a
	// block, none of which are context-cloned) has no single context to attribute to.

	/**
	 * @param list<TemplateContext> $contexts
	 */
	private function contextForScope(Scope $scope, array $contexts): ?TemplateContext
	{
		$functionName = $scope->getFunctionName();
		if ($functionName === null || preg_match('~^latteMain_ctx(\d+)$~', $functionName, $matches) !== 1) {
			return null;
		}

		return $contexts[(int) $matches[1]] ?? null;
	}

	private function describeChain(?TemplateContext $context): string
	{
		if ($context === null) {
			return 'unknown';
		}

		$chain = $context->getChain();

		return $chain === [] ? 'root (no incoming edges)' : implode(' -> ', $chain);
	}

	/**
	 * @param list<TemplateContext> $contexts
	 */
	private function describeUnion(array $contexts, string $name): string
	{
		$seen = [];
		$order = [];
		foreach ($contexts as $context) {
			$type = $context->getVars()[$name] ?? 'mixed';
			if (isset($seen[$type])) {
				continue;
			}

			$seen[$type] = true;
			$order[] = $type;
		}

		$count = count($contexts);

		return implode('|', $order) . ' across ' . $count . ' context' . ($count === 1 ? '' : 's');
	}

	private function error(string $message, FuncCall $node): IdentifierRuleError
	{
		return RuleErrorBuilder::message($message)
			->nonIgnorable()
			->identifier(self::IDENTIFIER)
			->line($node->getStartLine())
			->build();
	}

}
