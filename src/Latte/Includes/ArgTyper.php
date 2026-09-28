<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use Latte\MacroTokens;
use function ltrim;
use function strpos;
use function trim;

final class ArgTyper
{

	/**
	 * @return array{vars: array<string, string>, open: bool}
	 */
	public function typeArgs(IncludeTarget $site, TemplateContext $context): array
	{
		$tokens = new MacroTokens($site->getArgsSource());
		$vars = [];
		$open = false;

		while ($tokens->isNext(...MacroTokens::SIGNIFICANT)) {
			if ($tokens->nextValue('(expand)') !== null) {
				$open = true;
			} else {
				$name = $tokens->nextValue(MacroTokens::T_SYMBOL);
				if ($name !== null && ($tokens->nextToken('=>') !== null || $tokens->nextToken(':') !== null)) {
					$expr = trim($tokens->joinUntilSameDepth(','));
					$vars[$name] = $this->classifyExprType($expr, $context);
				}
			}

			$tokens->joinUntilSameDepth(',');
			$tokens->nextToken(',');
		}

		return ['vars' => $vars, 'open' => $open];
	}

	// Block params bind POSITIONALLY at the call site (BlockDispatchEliminator's own direct-call
	// rewrite matches by position), but typeArgs() above only recognizes `name: expr`/`name =>
	// expr` pairs - a bare positional argument is invisible to it. Consumers checking a block's
	// own declared params against what an edge provides must know when this gap could hide a
	// real binding, rather than silently trusting an unrelated same-named ambient value. Mirrors
	// typeArgs()'s own named-arg recognition token-for-token so the two can never disagree on
	// what counts as "named".
	public function hasPositionalArgs(IncludeTarget $site): bool
	{
		$tokens = new MacroTokens($site->getArgsSource());

		while ($tokens->isNext(...MacroTokens::SIGNIFICANT)) {
			if ($tokens->nextValue('(expand)') !== null) {
				$tokens->joinUntilSameDepth(',');
				$tokens->nextToken(',');

				continue;
			}

			$name = $tokens->nextValue(MacroTokens::T_SYMBOL);
			$isNamed = $name !== null && ($tokens->nextToken('=>') !== null || $tokens->nextToken(':') !== null);
			if (!$isNamed) {
				return true;
			}

			$tokens->joinUntilSameDepth(',');
			$tokens->nextToken(',');
		}

		return false;
	}

	private function classifyExprType(string $expr, TemplateContext $context): string
	{
		$tokens = new MacroTokens($expr);
		if ($tokens->nextToken(...MacroTokens::SIGNIFICANT) === null) {
			return 'mixed';
		}

		$isSingleToken = !$tokens->isNext(...MacroTokens::SIGNIFICANT);

		if ($tokens->isCurrent(MacroTokens::T_VARIABLE)) {
			if (!$isSingleToken) {
				return 'mixed';
			}

			$name = ltrim((string) $tokens->currentValue(), '$');

			return $context->getVars()[$name] ?? 'mixed';
		}

		if (!$isSingleToken) {
			return 'mixed';
		}

		if ($tokens->isCurrent(MacroTokens::T_NUMBER)) {
			return strpos((string) $tokens->currentValue(), '.') === false ? 'int' : 'float';
		}

		if ($tokens->isCurrent(MacroTokens::T_STRING)) {
			return 'string';
		}

		if ($tokens->isCurrent('true', 'TRUE', 'false', 'FALSE')) {
			return 'bool';
		}

		if ($tokens->isCurrent('null', 'NULL')) {
			return 'null';
		}

		return 'mixed';
	}

}
