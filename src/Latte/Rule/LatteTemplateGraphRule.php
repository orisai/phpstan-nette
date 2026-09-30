<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

// All four store-consuming Latte diagnostics (orisaiNette.latte.orphanTemplate, orisaiNette.latte.templateMissing,
// orisaiNette.latte.templateTypeMismatch, orisai.nette.latte.templateTypeRequired), reported from the merged collected data
// rather than from each template's own parse. That placement is the whole point: PHPStan runs
// CollectedDataNode rules after the result cache has already been restored AND saved, over
// cached-plus-fresh data for every analysed file, so the aggregation re-runs in full on every
// analysis while the per-file collection stays cached. A warm run therefore answers "is this
// template reachable", "which template hosts this renderer's missing view" and "which class does
// this template's renderer pair" from the corpus as it is NOW, not as it was when the template last
// changed - cold == warm by construction, with no whole-cache salt and no invalidation edge, which
// is what keeps the discovery store's ratified propagation granularity untouched. TemplateTypeChecker's
// own class doc carries the per-identifier reason none of the four may move back to the parse.
//
// Deliberately no fixNode() anywhere: see TemplateTypeChecker's own constraint note - this check
// UNDER-detects usage, so an auto-fix would delete files that are genuinely rendered.

/**
 * @implements Rule<CollectedDataNode>
 */
final class LatteTemplateGraphRule implements Rule
{

	private TemplateTypeChecker $checker;

	private bool $enabled;

	private bool $discoveryStoreEnabled;

	public function __construct(ConfigurationGuard $guard, TemplateTypeChecker $checker)
	{
		$guard->validate();
		$this->checker = $checker;
		$this->enabled = $guard->isLatteEnabled();
		$this->discoveryStoreEnabled = $guard->isLatteDiscoveryEnabled();
	}

	public function getNodeType(): string
	{
		return CollectedDataNode::class;
	}

	/**
	 * @param CollectedDataNode $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		// Before any other work: a flag-off consumer must pay no store read, walk or graph cost.
		if (!$this->enabled || !$this->discoveryStoreEnabled) {
			return [];
		}

		$errors = [];
		foreach ($this->checker->checkAggregate(
			$this->analysedTemplates($node),
			$this->templateTypeDeclarations($node),
			$this->conventionNameSites($node),
			$this->terminatingRenderMethods($node),
		) as $finding) {
			$diagnostic = $finding['diagnostic'];

			$builder = RuleErrorBuilder::message($diagnostic->getMessage())
				->identifier($diagnostic->getIdentifier())
				->file($finding['file'])
				->line($diagnostic->getLatteLine());

			$tip = $diagnostic->getTip();
			if ($tip !== null) {
				$builder->tip($tip);
			}

			$errors[] = $builder->build();
		}

		return $errors;
	}

	// The reportable set is the ANALYSED template set, never the whole .latte universe: a template
	// nobody asked PHPStan to analyse must not gain a finding just because the graph mentions it.
	// LatteAnalyzedFileMarkerCollector already fires exactly once per analysed .latte file whenever
	// the discovery store is on, which is the same condition this rule runs under.

	/**
	 * @return list<string>
	 */
	private function analysedTemplates(CollectedDataNode $node): array
	{
		$templates = [];
		foreach ($node->get(LatteAnalyzedFileMarkerCollector::class) as $perFile) {
			foreach ($perFile as $relPath) {
				$templates[] = $relPath;
			}
		}

		return $templates;
	}

	/**
	 * @return list<array{path: string, class: string|null, line: int}>
	 */
	private function templateTypeDeclarations(CollectedDataNode $node): array
	{
		$declarations = [];
		foreach ($node->get(LatteTemplateTypeDeclarationCollector::class) as $perFile) {
			foreach ($perFile as $declaration) {
				$declarations[] = $declaration;
			}
		}

		return $declarations;
	}

	/**
	 * @return list<array{class: string, name: string}>
	 */
	private function conventionNameSites(CollectedDataNode $node): array
	{
		$sites = [];
		foreach ($node->get(LatteConventionNameCollector::class) as $perFile) {
			foreach ($perFile as $perCall) {
				foreach ($perCall as $site) {
					$sites[] = $site;
				}
			}
		}

		return $sites;
	}

	/**
	 * @return list<string>
	 */
	private function terminatingRenderMethods(CollectedDataNode $node): array
	{
		$methods = [];
		foreach ($node->get(LatteTerminatingRenderCollector::class) as $perFile) {
			foreach ($perFile as $method) {
				$methods[] = $method;
			}
		}

		return $methods;
	}

}
