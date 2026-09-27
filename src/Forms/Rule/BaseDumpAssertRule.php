<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function count;
use function ltrim;
use function strtolower;

/**
 * @implements Rule<FuncCall>
 */
abstract class BaseDumpAssertRule implements Rule
{

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, bool $enabled)
	{
		$guard->validate();
		$this->enabled = $enabled;
	}

	public function getNodeType(): string
	{
		return FuncCall::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		if (!$node->name instanceof Name) {
			return [];
		}

		if ($node->isFirstClassCallable()) {
			return [];
		}

		$args = $node->getArgs();
		if (count($args) < $this->minArgs()) {
			return [];
		}

		if (strtolower(ltrim($node->name->toString(), '\\')) !== $this->functionName()) {
			return [];
		}

		return $this->buildErrors($args, $node, $scope);
	}

	abstract protected function functionName(): string;

	abstract protected function minArgs(): int;

	/**
	 * @param array<Arg> $args
	 * @return list<IdentifierRuleError>
	 */
	abstract protected function buildErrors(array $args, FuncCall $node, Scope $scope): array;

	protected function buildError(string $message, string $identifier, FuncCall $node): IdentifierRuleError
	{
		return RuleErrorBuilder::message($message)
			->nonIgnorable()
			->identifier($identifier)
			->line($node->getStartLine())
			->build();
	}

}
