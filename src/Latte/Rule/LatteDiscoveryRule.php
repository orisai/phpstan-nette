<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\MutationFact;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function sprintf;
use function strpos;
use function substr_compare;

/**
 * @implements Rule<InClassNode>
 */
final class LatteDiscoveryRule implements Rule
{

	public const OPAQUE_IDENTIFIER = 'orisai.nette.latte.fileDiscoveryOpaque';

	public const MUTATION_IDENTIFIER = 'orisai.nette.latte.ineffectiveTemplateMutation';

	private PhpRenderWalk $renderWalk;

	private PhpFactsCache $renderFactsCache;

	private bool $enabled;

	private bool $discoveryEnabled;

	private bool $mappingSourceConfigured;

	public function __construct(ConfigurationGuard $guard, PhpRenderWalk $renderWalk, PhpFactsCache $renderFactsCache)
	{
		$guard->validate();
		$this->renderWalk = $renderWalk;
		$this->renderFactsCache = $renderFactsCache;
		$this->enabled = $guard->isLatteEnabled();
		$this->discoveryEnabled = $guard->isLatteDiscoveryEnabled();
		$this->mappingSourceConfigured = $guard->hasTemplateFactoryContainerLoader();
	}

	public function getNodeType(): string
	{
		return InClassNode::class;
	}

	/**
	 * @param InClassNode $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		// Before any other work: a flag-off consumer must pay no walk or cache cost at all.
		if (!$this->enabled) {
			return [];
		}

		// Compiled LatteTpl_* classes report the .latte file itself as their source - raw Latte
		// source is unparseable for the walk, and discovery describes hand-written render-side
		// classes, never compiled template output.
		if (substr_compare($scope->getFile(), '.latte', -6) === 0) {
			return [];
		}

		$classReflection = $node->getClassReflection();
		if (!$classReflection->isClass() || $classReflection->isAnonymous()) {
			return [];
		}

		$walk = $this->renderWalk;
		$className = $classReflection->getName();
		$facts = $this->renderFactsCache->remember(
			$className,
			static fn (): PhpRenderFacts => $walk->factsFor($className),
		);

		// Shared qualification gate: a non-qualifying class carries no discovery fact at all.
		if (!Qualification::qualifies($facts)) {
			return [];
		}

		$errors = [];
		$discovery = $facts->getDiscovery();
		$ownSetFileLines = self::ownSetFileLines($facts, $scope->getFile());
		// With discovery switched off there is nothing to be opaque about.
		if (
			$this->discoveryEnabled
			&& $discovery !== null
			&& !self::nothingToDiscover($classReflection, $facts, $scope->getFile(), $ownSetFileLines)
		) {
			foreach ($discovery->getOpaques() as $opaque) {
				// With no mapping source configured every presenter reads as unmapped, which tells the user
				// nothing; the fact keeps the opaque, so templateMissing still backs off from it.
				if (
					!$this->mappingSourceConfigured
					&& strpos($opaque['reason'], DiscoveryResolver::UNRESOLVED_PRESENTER_REASON) === 0
				) {
					continue;
				}

				$line = $opaque['line'];
				$errors[] = RuleErrorBuilder::message(
					sprintf('Template file discovery is opaque: %s.', $opaque['reason']),
				)
					->identifier(self::OPAQUE_IDENTIFIER)
					// Every non-null opaque line is a setFile site line, and the walk follows calls
					// into ancestors - a site the analysed file does not own belongs to an ancestor's
					// file, where its line number means nothing. Those (and holes with no site at all:
					// unresolved presenter name, unassigned locator override) anchor at the class
					// declaration instead.
					->line($line !== null && isset($ownSetFileLines[$line]) ? $line : $node->getStartLine())
					->build();
			}
		}

		// MutationFact carries no site file (unlike SetFileFact above), so a setView/changeAction
		// inherited from an ancestor is reported at the ancestor's line number in this file - no
		// corpus occurrence, and closing it needs the site file on the fact itself.
		foreach ($facts->getMutations() as $mutation) {
			// EFFECTIVE_MAYBE is the conservative state (spec section 3) - only a provably
			// out-of-window call is reported.
			if ($mutation->getEffectiveness() !== MutationFact::EFFECTIVE_NO) {
				continue;
			}

			$errors[] = RuleErrorBuilder::message(sprintf(
				'Call to %s() has no effect at this point of the presenter lifecycle.',
				$mutation->getKind(),
			))
				->identifier(self::MUTATION_IDENTIFIER)
				->line($mutation->getLine())
				->build();
		}

		return $errors;
	}

	// An abstract class is never the class that runs: Nette's own PresenterFactory rejects an
	// abstract presenter outright and no control can be instantiated either. A hole in ITS discovery
	// is worth reporting only when the class contributes a renderable site of its own - an own
	// setFile (an abstract control base whose createTemplate() writes a dynamic path is exactly
	// that) or an own view site (an action/render hook, a setView call). With neither, there is
	// nothing to discover here at all: every concrete descendant carries its own facts, resolves its
	// own candidates under its own name and reports its own holes.

	/**
	 * @param array<int, true> $ownSetFileLines
	 */
	private static function nothingToDiscover(
		ClassReflection $classReflection,
		PhpRenderFacts $facts,
		string $file,
		array $ownSetFileLines
	): bool
	{
		if (!$classReflection->isAbstract() || $ownSetFileLines !== []) {
			return false;
		}

		foreach ($facts->getViews() as $view) {
			foreach ($view->getSites() as $site) {
				if ($site['file'] === $file) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @return array<int, true>
	 */
	private static function ownSetFileLines(PhpRenderFacts $facts, string $file): array
	{
		$lines = [];
		foreach ($facts->getSetFileTargets() as $target) {
			$site = $target->getSite();
			if ($site['file'] === $file) {
				$lines[$site['line']] = true;
			}
		}

		return $lines;
	}

}
