<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Latte\FixSupport;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
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

		if (FixSupport::supportsFixes($scope)) {
			return $errors;
		}

		return array_map([self::class, 'stripFix'], $errors);
	}

	// Belt-and-braces for rules that do not consult FixSupport (PHPStan core rules ship several
	// fixNode() callers, e.g. OverridingMethodRule/OverridingPropertyRule/BacktickRule) - rules that
	// do consult it skip fixNode() themselves, this is the fallback for everything else.
	private static function stripFix(IdentifierRuleError $error): IdentifierRuleError
	{
		if (!$error instanceof FixableNodeRuleError) {
			return $error;
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
