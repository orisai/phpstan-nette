<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use Latte\Token;
use function in_array;
use function trim;

// Vendor Latte never states on a token whether it opens a body: {tag/} sets Token::$empty, but
// AUTO_EMPTY tags and CoreMacros' {_ '...'} shorthand are resolved inside the compiler instead.
// Both TemplateFactExtractor's depth tracker and the LatteForms form-scope walk depend on the
// same answer, so it lives here once rather than in each walk.
final class MacroPairing
{

	// Tags that always appear as {name}...{/name} pairs; a name missing here is treated as a
	// single tag and never opens a nesting level, so {var} inside it would wrongly count as top-level.
	public const PAIR_TAGS = [
		'if', 'ifset', 'ifcontent', 'ifchanged', 'switch', 'foreach', 'iterateWhile',
		'for', 'while', 'first', 'last', 'sep', 'try', '_', 'translate', 'capture',
		'spaceless', 'tag', 'snippet', 'block', 'define', 'embed', 'snippetArea',
		'ifCurrent', 'form', 'formContext', 'formContainer', 'label', 'name', 'cache',
	];

	// Tags registered with Macro::AUTO_EMPTY (vendor/nette/forms/src/Bridges/FormsLatte/FormMacros.php:37);
	// resolved via forward lookahead by vendor Latte\Compiler\Compiler::processMacroTag().
	private const AUTO_EMPTY_TAGS = ['label'];

	// Latte\Parser::N_PREFIX is 'n:', but it is marked @internal so it is inlined here rather than referenced.
	public const N_ATTRIBUTE_PREFIX = 'n:';

	private function __construct()
	{
	}

	/**
	 * @param array<Token> $tokens
	 */
	public static function opensBody(array $tokens, int $index): bool
	{
		$token = $tokens[$index];

		return !$token->empty
			&& in_array($token->name, self::PAIR_TAGS, true)
			&& !self::isGettextShorthand($token)
			&& !(in_array($token->name, self::AUTO_EMPTY_TAGS, true) && self::isAutoEmptySelfClosing(
				$tokens,
				$index,
				$token->name,
			));
	}

	// {/} (Parser::parseMacroTag() leaves name === '' for a bare closing tag) closes the nearest
	// open pair tag regardless of its name.
	public static function closesBody(Token $token): bool
	{
		return $token->name === '' || in_array($token->name, self::PAIR_TAGS, true);
	}

	/**
	 * @param array<Token> $tokens
	 */
	private static function isAutoEmptySelfClosing(array $tokens, int $index, string $name): bool
	{
		$pos = $index;
		while (isset($tokens[++$pos])) {
			$next = $tokens[$pos];
			if (
				($next->type === Token::MACRO_TAG && $next->name === $name)
				|| ($next->type === Token::HTML_ATTRIBUTE_BEGIN && $next->name === self::N_ATTRIBUTE_PREFIX . $name)
			) {
				return !$next->closing;
			}
		}

		return true;
	}

	// CoreMacros::macroTranslate self-closes {_ 'text'} (non-empty args) but keeps {_}...{/_}
	// as a pair when args are empty; vendor never surfaces this on the token itself (only /}
	// does), so the depth tracker must special-case it or {_'...'} permanently inflates $depth.
	private static function isGettextShorthand(Token $token): bool
	{
		return $token->name === '_' && trim($token->value) !== '';
	}

}
