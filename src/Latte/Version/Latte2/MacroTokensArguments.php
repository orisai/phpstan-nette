<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte2;

use Latte\MacroTokens;
use OriPhpstan\Nette\Latte\Includes\TagArgument;
use function ltrim;
use function strpos;
use function trim;

// The MacroTokens grammar every Latte 2 consumer of an argument list used to run itself:
// `name: expr` / `name => expr` pairs, `(expand)` spreads and bare expressions, comma-separated
// at depth 0. A symbol not followed by `=>`/`:` is the start of a bare expression.
final class MacroTokensArguments
{

	private function __construct()
	{
	}

	/**
	 * @return list<TagArgument>
	 */
	public static function parse(string $argsSource): array
	{
		$tokens = new MacroTokens($argsSource);
		$args = [];

		while ($tokens->isNext(...MacroTokens::SIGNIFICANT)) {
			if ($tokens->nextValue('(expand)') !== null) {
				$args[] = new TagArgument(null, trim($tokens->joinUntilSameDepth(',')), true);
				$tokens->nextToken(',');

				continue;
			}

			$name = $tokens->nextValue(MacroTokens::T_SYMBOL);
			$isNamed = $name !== null && ($tokens->nextToken('=>') !== null || $tokens->nextToken(':') !== null);
			$rest = trim($tokens->joinUntilSameDepth(','));
			$tokens->nextToken(',');

			$args[] = $isNamed
				? self::classified($name, $rest)
				: self::classified(null, $name === null ? $rest : trim($name . ' ' . $rest));
		}

		return $args;
	}

	private static function classified(?string $name, string $expr): TagArgument
	{
		$tokens = new MacroTokens($expr);
		if ($tokens->nextToken(...MacroTokens::SIGNIFICANT) === null) {
			return new TagArgument($name, $expr, false);
		}

		$isSingleToken = !$tokens->isNext(...MacroTokens::SIGNIFICANT);
		if ($tokens->isCurrent(MacroTokens::T_VARIABLE)) {
			return new TagArgument(
				$name,
				$expr,
				false,
				$isSingleToken ? ltrim((string) $tokens->currentValue(), '$') : null,
			);
		}

		return new TagArgument($name, $expr, false, null, $isSingleToken ? self::literalType($tokens) : null);
	}

	/**
	 * @return TagArgument::LITERAL_*|null
	 */
	private static function literalType(MacroTokens $tokens): ?string
	{
		if ($tokens->isCurrent(MacroTokens::T_NUMBER)) {
			return strpos((string) $tokens->currentValue(), '.') === false
				? TagArgument::LITERAL_INT
				: TagArgument::LITERAL_FLOAT;
		}

		if ($tokens->isCurrent(MacroTokens::T_STRING)) {
			return TagArgument::LITERAL_STRING;
		}

		if ($tokens->isCurrent('true', 'TRUE', 'false', 'FALSE')) {
			return TagArgument::LITERAL_BOOL;
		}

		if ($tokens->isCurrent('null', 'NULL')) {
			return TagArgument::LITERAL_NULL;
		}

		return null;
	}

}
