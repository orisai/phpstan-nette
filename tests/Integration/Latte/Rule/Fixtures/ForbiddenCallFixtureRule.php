<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Rule\Fixtures;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function sprintf;
use function strtolower;

// Stands in for shipmonk's ForbidCustomFunctionsRule (same message shapes): denies the configured
// functions and Class::method static calls wherever they appear, generated template code included.
/**
 * @implements Rule<Node>
 */
final class ForbiddenCallFixtureRule implements Rule
{

	/** @var array<string, string> */
	private array $denied = [];

	/**
	 * @param array<string, string> $denied
	 */
	public function __construct(array $denied)
	{
		foreach ($denied as $name => $reason) {
			$this->denied[strtolower($name)] = $reason;
		}
	}

	public function getNodeType(): string
	{
		return Node::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ($node instanceof FuncCall && $node->name instanceof Name) {
			$name = $scope->resolveName($node->name);
			$reason = $this->denied[strtolower($name)] ?? null;

			return $reason === null ? [] : [$this->error(sprintf('Function %s() is forbidden.', $name), $reason)];
		}

		if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
			$name = $scope->resolveName($node->class) . '::' . $node->name->toString();
			$reason = $this->denied[strtolower($name)] ?? null;

			return $reason === null ? [] : [$this->error(sprintf('Method %s() is forbidden.', $name), $reason)];
		}

		return [];
	}

	private function error(string $message, string $reason): IdentifierRuleError
	{
		return RuleErrorBuilder::message($message . ' ' . $reason)
			->identifier('test.forbiddenCall')
			->build();
	}

}
