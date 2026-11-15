<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Latte\FixSupport;
use OriPhpstan\Nette\Latte\TemplatePath;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\FileDependenciesRuleError;
use PHPStan\Rules\FileRuleError;
use PHPStan\Rules\FixableNodeRuleError;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\MetadataRuleError;
use PHPStan\Rules\NonIgnorableRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Rules\TipRuleError;
use function array_map;

/**
 * @template TNodeType of Node
 * @implements Rule<TNodeType>
 */
final class LatteFixStrippingRule implements Rule
{

	/** @var Rule<TNodeType> */
	private Rule $rule;

	/**
	 * @param Rule<TNodeType> $rule
	 */
	public function __construct(Rule $rule)
	{
		$this->rule = $rule;
	}

	public function getNodeType(): string
	{
		return $this->rule->getNodeType();
	}

	/**
	 * @param TNodeType $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$errors = $this->rule->processNode($node, $scope);
		$templateScope = !FixSupport::supportsFixes($scope);

		return array_map(
			static fn (IdentifierRuleError $error): IdentifierRuleError => $templateScope || self::fixReachesTemplate(
				$error,
			)
				? self::stripFix($error, $scope)
				: $error,
			$errors,
		);
	}

	private static function fixReachesTemplate(IdentifierRuleError $error): bool
	{
		if (!$error instanceof FixableNodeRuleError) {
			return false;
		}

		if ($error instanceof FileRuleError && TemplatePath::is($error->getFile())) {
			return true;
		}

		foreach ($error->getNodeFixes() as $nodeFix) {
			if ($nodeFix->file !== null && TemplatePath::is($nodeFix->file)) {
				return true;
			}
		}

		return false;
	}

	// Belt-and-braces for rules that do not consult FixSupport (PHPStan core rules ship several
	// fixNode() callers, e.g. OverridingMethodRule/OverridingPropertyRule/BacktickRule) - rules that
	// do consult it skip fixNode() themselves, this is the fallback for everything else.
	private static function stripFix(IdentifierRuleError $error, DependencyTracker $scope): IdentifierRuleError
	{
		if (!$error instanceof FixableNodeRuleError) {
			return $error;
		}

		if ($error instanceof FileDependenciesRuleError) {
			foreach ($error->getFileDependencies() as $file) {
				$scope->trackFileDependency($file);
			}
		}

		$builder = RuleErrorBuilder::message($error->getMessage())
			->identifier($error->getIdentifier());

		if ($error instanceof TipRuleError) {
			$builder->tip($error->getTip());
		}

		if ($error instanceof LineRuleError) {
			$builder->line($error->getLine());
		}

		if ($error instanceof MetadataRuleError) {
			$builder->metadata($error->getMetadata());
		}

		if ($error instanceof NonIgnorableRuleError) {
			$builder->nonIgnorable();
		}

		if ($error instanceof FileRuleError) {
			$builder->file($error->getFile(), $error->getFileDescription());
		}

		return $builder->build();
	}

}
