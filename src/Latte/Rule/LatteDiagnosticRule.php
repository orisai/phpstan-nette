<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Runtime\Diag;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function count;

/**
 * @implements Rule<StaticCall>
 */
final class LatteDiagnosticRule implements Rule
{

	public function __construct(ConfigurationGuard $guard)
	{
		$guard->validate();
	}

	public function getNodeType(): string
	{
		return StaticCall::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
			return [];
		}

		if ($node->name->toString() !== 'report') {
			return [];
		}

		if ($scope->resolveName($node->class) !== Diag::class) {
			return [];
		}

		$argCount = count($node->getArgs());
		if ($node->isFirstClassCallable() || $argCount < 2 || $argCount > 3) {
			return [$this->buildInternalError($node)];
		}

		$identifier = $this->constantStringArg($node, $scope, 0);
		$message = $this->constantStringArg($node, $scope, 1);

		if ($identifier === null || $message === null) {
			return [$this->buildInternalError($node)];
		}

		$builder = RuleErrorBuilder::message($message)
			->identifier($identifier)
			->line($node->getStartLine());

		if ($argCount === 3) {
			$tip = $this->constantStringArg($node, $scope, 2);
			if ($tip === null) {
				return [$this->buildInternalError($node)];
			}

			$builder->tip($tip);
		}

		// Deliberately no fixNode() anywhere in this rule: the pipeline's own diagnostics include
		// deadness-style findings (orisaiNette.latte.orphanTemplate) built on UNDER-detected usage, where an
		// auto-fix would delete files that are genuinely rendered - see TemplateTypeChecker's
		// constraint note. Nothing here is ever a FixableNodeRuleError.
		return [$builder->build()];
	}

	private function constantStringArg(StaticCall $node, Scope $scope, int $index): ?string
	{
		$args = $node->getArgs();
		if (!isset($args[$index]) || $args[$index]->name !== null) {
			return null;
		}

		$constantStrings = $scope->getType($args[$index]->value)->getConstantStrings();
		if (count($constantStrings) !== 1) {
			return null;
		}

		return $constantStrings[0]->getValue();
	}

	private function buildInternalError(StaticCall $node): IdentifierRuleError
	{
		return RuleErrorBuilder::message(
			Diag::class . '::report() called with non-constant arguments; the Latte analysis pipeline must '
			. 'always materialize literal-string identifier/message pairs.',
		)
			->identifier('orisaiNette.latte.internalError')
			->line($node->getStartLine())
			->build();
	}

}
