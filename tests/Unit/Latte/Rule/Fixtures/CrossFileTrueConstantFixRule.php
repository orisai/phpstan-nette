<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ConstFetch>
 */
final class CrossFileTrueConstantFixRule implements Rule
{

	public const ReportedInTarget = 'reportedInTarget';

	public const NodeFixFile = 'nodeFixFile';

	private Parser $parser;

	private string $targetFile;

	private string $mode;

	public function __construct(Parser $parser, string $targetFile, string $mode)
	{
		$this->parser = $parser;
		$this->targetFile = $targetFile;
		$this->mode = $mode;
	}

	public function getNodeType(): string
	{
		return ConstFetch::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ($node->name->toLowerString() !== 'true' || $scope->getFile() === $this->targetFile) {
			return [];
		}

		$target = TrueConstantFinder::find($this->parser, $this->targetFile);
		$builder = RuleErrorBuilder::message('true constant in another file.')
			->identifier('test.crossFileTrueConstant');

		if ($this->mode === self::ReportedInTarget) {
			$builder->file($this->targetFile)
				->line($target->getStartLine())
				->fixNode($target, static fn (ConstFetch $node): Node\Expr => new ConstFetch(new Name('false')));
		} else {
			$builder->fixNode($target, static fn (ConstFetch $node): Node\Expr => new ConstFetch(new Name('false')), $this->targetFile);
		}

		return [$builder->build()];
	}

}
