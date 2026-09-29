<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\TagLexer;
use Latte\Compiler\Token;
use OriPhpstan\Nette\Latte\Includes\TagArgument;
use function count;
use function ltrim;
use function trim;

// The argument grammar MacroTokensArguments reads for Latte 2, over Latte 3's tag lexer: `name:
// expr` / `name => expr` pairs, `(expand)` and `...` spreads and bare expressions, comma-separated
// at depth 0. An identifier not followed by `=>`/`:` starts a bare expression.
final class TagLexerArguments
{

	private const OPENERS = ['(', '[', '{'];

	private const CLOSERS = [')', ']', '}'];

	private function __construct()
	{
	}

	/**
	 * @return list<TagArgument>
	 */
	public static function parse(string $argsSource): array
	{
		$args = [];
		foreach (self::split((new TagLexer())->tokenize($argsSource)) as $tokens) {
			$args[] = self::argument($tokens);
		}

		return $args;
	}

	/**
	 * @param array<Token> $tokens
	 * @return list<list<Token>>
	 */
	private static function split(array $tokens): array
	{
		$groups = [];
		$current = [];
		$depth = 0;

		foreach ($tokens as $token) {
			if ($token->isEnd()) {
				break;
			}

			if ($depth === 0 && $token->is(',')) {
				$groups[] = $current;
				$current = [];

				continue;
			}

			if ($token->is(...self::OPENERS)) {
				$depth++;
			} elseif ($token->is(...self::CLOSERS)) {
				$depth--;
			}

			$current[] = $token;
		}

		if (self::significant($current) !== []) {
			$groups[] = $current;
		}

		return $groups;
	}

	/**
	 * @param list<Token> $tokens
	 */
	private static function argument(array $tokens): TagArgument
	{
		$significant = self::significant($tokens);
		$first = $significant[0] ?? null;
		if ($first === null) {
			return new TagArgument(null, '', false);
		}

		if ($first->is(Token::Php_ExpandCast, Token::Php_Ellipsis)) {
			return new TagArgument(null, self::text($tokens), true);
		}

		$separator = $significant[1] ?? null;
		if ($first->is(Token::Php_Identifier) && $separator !== null && $separator->is('=>', ':')) {
			$rest = [];
			$passed = false;
			foreach ($tokens as $token) {
				if ($passed) {
					$rest[] = $token;
				} elseif ($token === $separator) {
					$passed = true;
				}
			}

			return self::classified($first->text, $rest);
		}

		return self::classified(null, $tokens);
	}

	/**
	 * @param list<Token> $tokens
	 */
	private static function classified(?string $name, array $tokens): TagArgument
	{
		$significant = self::significant($tokens);
		$source = self::text($tokens);
		if (count($significant) !== 1) {
			return new TagArgument($name, $source, false);
		}

		$token = $significant[0];
		if ($token->is(Token::Php_Variable)) {
			return new TagArgument($name, $source, false, ltrim($token->text, '$'));
		}

		return new TagArgument($name, $source, false, null, self::literalType($token));
	}

	/**
	 * @return TagArgument::LITERAL_*|null
	 */
	private static function literalType(Token $token): ?string
	{
		if ($token->is(Token::Php_Integer)) {
			return TagArgument::LITERAL_INT;
		}

		if ($token->is(Token::Php_Float)) {
			return TagArgument::LITERAL_FLOAT;
		}

		if ($token->is(Token::Php_ConstantEncapsedString)) {
			return TagArgument::LITERAL_STRING;
		}

		if ($token->is(Token::Php_True, Token::Php_False)) {
			return TagArgument::LITERAL_BOOL;
		}

		if ($token->is(Token::Php_Null)) {
			return TagArgument::LITERAL_NULL;
		}

		return null;
	}

	/**
	 * @param list<Token> $tokens
	 * @return list<Token>
	 */
	private static function significant(array $tokens): array
	{
		$significant = [];
		foreach ($tokens as $token) {
			if (!$token->is(Token::Php_Whitespace, Token::Php_Comment)) {
				$significant[] = $token;
			}
		}

		return $significant;
	}

	/**
	 * @param list<Token> $tokens
	 */
	private static function text(array $tokens): string
	{
		$text = '';
		foreach ($tokens as $token) {
			$text .= $token->text;
		}

		return trim($text);
	}

}
