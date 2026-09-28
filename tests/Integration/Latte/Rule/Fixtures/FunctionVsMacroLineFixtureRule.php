<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Rule\Fixtures;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

// A single rule flags two node kinds sharing one
// macro-bearing source line - a harvested-function call (FilterRewriter-rewritten, must never
// get a tip, however macro-bearing the line) and a plain ConstFetch that is NOT FilterRewriter
// output at all (must still get the line-map tip, even though it over-attributes to whichever
// macro happens to share the line - the accepted direction).
/**
 * @implements Rule<Node>
 */
final class FunctionVsMacroLineFixtureRule implements Rule
{

	public function getNodeType(): string
	{
		return Node::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (
			$node instanceof StaticCall
			&& $node->name instanceof Identifier
			&& $node->name->toLowerString() === 'clamp'
		) {
			return [
				RuleErrorBuilder::message('clamp call denied.')
					->identifier('test.functionVsMacroLine')
					->build(),
			];
		}

		if ($node instanceof ConstFetch && $node->name->toLowerString() === 'true') {
			return [
				RuleErrorBuilder::message('true constant denied.')
					->identifier('test.functionVsMacroLine')
					->build(),
			];
		}

		return [];
	}

}
