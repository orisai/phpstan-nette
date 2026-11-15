<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Parser\Parser;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<CollectedDataNode>
 */
final class CollectedDataTrueConstantFixRule implements Rule
{

	private Parser $parser;

	private string $targetFile;

	public function __construct(Parser $parser, string $targetFile)
	{
		$this->parser = $parser;
		$this->targetFile = $targetFile;
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
		if ($node->get(AnalysedFileCollector::class) === []) {
			return [];
		}

		$target = TrueConstantFinder::find($this->parser, $this->targetFile);

		return [
			RuleErrorBuilder::message('true constant found after the analysis.')
				->identifier('test.collectedDataTrueConstant')
				->file($this->targetFile)
				->line($target->getStartLine())
				->fixNode($target, static fn (ConstFetch $node): Node\Expr => new ConstFetch(new Name('false')))
				->build(),
		];
	}

}
