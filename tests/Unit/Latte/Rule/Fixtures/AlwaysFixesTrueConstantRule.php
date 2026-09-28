<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ConstFetch>
 */
final class AlwaysFixesTrueConstantRule implements Rule
{

	public function getNodeType(): string
	{
		return ConstFetch::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ($node->name->toLowerString() !== 'true') {
			return [];
		}

		return [
			RuleErrorBuilder::message('true constant is forbidden by this test fixture.')
				->identifier('test.alwaysFixableTrueConstant')
				->fixNode($node, static fn (ConstFetch $node): Node\Expr => new ConstFetch(new Name('false')))
				->build(),
		];
	}

}
