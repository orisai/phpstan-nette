<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte2;

use InvalidArgumentException;
use Latte\CompileException;
use Latte\MacroTokens;
use Latte\Parser;
use Latte\RegexpException;
use Latte\Token;
use OriPhpstan\Nette\Latte\Includes\BlockBodyTracker;
use OriPhpstan\Nette\Latte\Includes\IncludePath;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use function in_array;
use function ltrim;
use function preg_match;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;

final class TemplateFactExtractor
{

	private const INCLUDE_FAMILY_TAGS = ['include', 'extends', 'layout', 'import', 'sandbox', 'embed', 'includeblock'];

	private const LAYOUT_TAGS = ['extends', 'layout'];

	// Parser::parseMacroTag() gives the implicit-print shorthand ({$expr}, or an explicit {=$expr})
	// this synthetic name - it is compiled code the developer wrote directly, not a hidden macro
	// transformation, so it is excluded from lineMacros (see getLineMacros()'s own doc).
	private const IMPLICIT_PRINT_TAG = '=';

	public function extract(string $latteSource, string $projectRelativePath): TemplateFacts
	{
		try {
			return $this->doExtract($latteSource, $projectRelativePath);
		} catch (CompileException | RegexpException | InvalidArgumentException $e) {
			return $this->empty();
		}
	}

	private function doExtract(string $latteSource, string $projectRelativePath): TemplateFacts
	{
		$tokens = (new Parser())->parse($latteSource);

		$includeSites = [];
		$blockNames = [];
		$defineNames = [];
		$topLevelVars = [];
		$topLevelDefaults = [];
		$blockDeclaredVars = [];
		$blockDeclaredVarLines = [];
		$lineMacros = [];
		$layoutMode = null;

		// 1-indexed column of the NEXT token's first character - every token's own $text is a
		// literal slice of the raw source (concatenating them in order reproduces it exactly), so
		// running this counter across every token (not just MACRO_TAG ones, which is why this
		// must update before the type-check `continue` below) gives each macro tag's real column.
		$column = 1;

		// A {varType} matches the innermost open {block}/{define}'s own top level only - one
		// nested inside an {if}/{foreach} within it is an ordinary mid-file declaration instead,
		// and a nested {define} gets its own frame that shadows the outer one without ever mixing
		// their maps. Shared with DeclarationScanner's placement exemption - see BlockBodyTracker.
		$blocks = new BlockBodyTracker();

		foreach ($tokens as $index => $token) {
			$tokenColumn = $column;
			$lastNewlineOffset = strrpos($token->text, "\n");
			$column = $lastNewlineOffset === false
				? $column + strlen($token->text)
				: strlen($token->text) - $lastNewlineOffset;

			// n:attribute pairs (n:if, n:foreach, ...) never emit MACRO_TAG tokens - only
			// HTML_ATTRIBUTE_BEGIN/END - so their bodies are invisible to this depth tracker;
			// e.g. tags inside <div n:if> count as top-level, same as outside the div.
			if ($token->type !== Token::MACRO_TAG) {
				continue;
			}

			if ($token->closing) {
				$blocks->closing(MacroPairing::closesBody($token));

				continue;
			}

			// Every occurrence is recorded (never deduped by name): two same-named macro tags on
			// one line sit at different columns and are genuinely distinct locations - collapsing
			// them would throw away the precision columns exist to provide.
			if ($token->name !== '' && $token->name !== self::IMPLICIT_PRINT_TAG) {
				$lineMacros[$token->line][] = ['name' => $token->name, 'column' => $tokenColumn];
			}

			$opensBody = MacroPairing::opensBody($tokens, (int) $index);

			if (in_array($token->name, self::INCLUDE_FAMILY_TAGS, true)) {
				if ($layoutMode === null && in_array($token->name, self::LAYOUT_TAGS, true)) {
					$layoutMode = $this->layoutModeFor($token);
				}

				$site = $this->extractIncludeTarget($token, $projectRelativePath);
				if ($site !== null) {
					$includeSites[] = $site;
				}
			} elseif ($token->name === 'block') {
				$name = $this->scanBlockOrDefineName($token->value . $token->modifiers);
				if ($name !== '' && strpos($name, '$') === false) {
					$blockNames[] = $name;
				}

				if ($opensBody) {
					$blocks->enterBlockOrDefine($name);
				}
			} elseif ($token->name === 'define') {
				$name = $this->scanBlockOrDefineName($token->value . $token->modifiers);
				if ($name !== '' && strpos($name, '$') === false) {
					$defineNames[] = $name;
				}

				if ($opensBody) {
					$blocks->enterBlockOrDefine($name);
				}
			} elseif ($token->name === 'var' && $blocks->depth() === 0) {
				foreach ($this->scanTopLevelVarLiterals($token->value . $token->modifiers) as $varName => $type) {
					$topLevelVars[$varName] = $type;
				}
			} elseif ($token->name === 'default' && $blocks->depth() === 0) {
				foreach ($this->scanTopLevelDefaultNames($token->value . $token->modifiers) as $varName) {
					$topLevelDefaults[] = $varName;
				}
			} elseif ($token->name === 'varType' && $blocks->isAtOwnTopLevel()) {
				$entry = $this->scanBlockVarType($token->value . $token->modifiers);
				$frame = $blocks->currentFrame();
				if ($entry !== null && $frame !== null) {
					[$varName, $type] = $entry;
					$blockDeclaredVars[$frame['name']][$varName] = $type;
					$blockDeclaredVarLines[$frame['name']][$varName] = $token->line;
				}
			}

			$blocks->advance($opensBody);
		}

		return new TemplateFacts(
			$includeSites,
			$blockNames,
			$defineNames,
			$topLevelVars,
			$topLevelDefaults,
			$blockDeclaredVars,
			$blockDeclaredVarLines,
			$lineMacros,
			$layoutMode,
		);
	}

	// {layout none} / {layout auto} produce no include site at all (extractIncludeTarget() below
	// returns null for both), so the DECLARATION itself has to be recorded separately - it is what
	// decides whether vendor's presenter-side auto-layout walk still runs for this template.

	/**
	 * @return TemplateFacts::LAYOUT_MODE_*
	 */
	private function layoutModeFor(Token $token): string
	{
		$args = trim($token->value);

		if ($args === TemplateFacts::LAYOUT_MODE_NONE) {
			return TemplateFacts::LAYOUT_MODE_NONE;
		}

		if ($args === TemplateFacts::LAYOUT_MODE_AUTO) {
			return TemplateFacts::LAYOUT_MODE_AUTO;
		}

		return TemplateFacts::LAYOUT_MODE_DECLARED;
	}

	private function extractIncludeTarget(Token $token, string $projectRelativePath): ?IncludeTarget
	{
		$tag = $token->name;

		if (($tag === 'extends' || $tag === 'layout') && in_array(trim($token->value), ['none', 'auto'], true)) {
			return null;
		}

		// BlockMacros/CoreMacros never reconcatenate modifiers into the tokenizer for these
		// tags (unlike {var}/{varType}): modifiers stay real escape filters (e.g. |noescape),
		// and reconcatenating them here would make fetchWord()'s '|'-continuation swallow the
		// filter suffix into the target.
		$tokens = new MacroTokens($token->value);

		if ($tag === 'include' || $tag === 'embed') {
			$fetched = $tokens->fetchWordWithModifier($tag === 'include' ? ['block', 'file', '#'] : ['block', 'file']);
			if ($fetched === null) {
				return null;
			}

			[$name, $mod] = $fetched;
			$isBlockMode = $mod === 'block' || $mod === '#'
				|| ($mod === null && preg_match('~^[\w-]+$~D', $name) === 1);
		} else {
			$name = $tokens->fetchWord();
			if ($name === null) {
				return null;
			}

			$isBlockMode = false;
		}

		$argsSource = trim($tokens->joinAll());

		if (strpos($name, '$') !== false || strpos($name, '(') !== false) {
			return new IncludeTarget($tag, IncludeTarget::KIND_DYNAMIC, trim($name), null, $argsSource, $token->line);
		}

		if ($isBlockMode) {
			$rawTarget = ltrim($this->dequote(trim($name)), '#');

			return new IncludeTarget(
				$tag,
				IncludeTarget::KIND_STATIC_BLOCK,
				$rawTarget,
				null,
				$argsSource,
				$token->line,
			);
		}

		$literal = $this->dequote(trim($name));
		$resolvedPath = IncludePath::normalize($projectRelativePath, $literal);

		return new IncludeTarget(
			$tag,
			IncludeTarget::KIND_STATIC_FILE,
			$literal,
			$resolvedPath,
			$argsSource,
			$token->line,
		);
	}

	private function scanBlockOrDefineName(string $value): string
	{
		$tokens = new MacroTokens($value);
		$fetched = $tokens->fetchWordWithModifier('local');

		return $fetched === null ? '' : DeclarationScanner::blockNameWord($fetched[0]);
	}

	/**
	 * @return array<string, string>
	 */
	private function scanTopLevelVarLiterals(string $value): array
	{
		$result = [];
		$tokens = new MacroTokens($value);

		$expectingName = true;
		$hasType = false;
		$skip = false;
		$name = null;

		while ($tokens->nextToken() !== null) {
			if (
				$expectingName
				&& $tokens->isCurrent(MacroTokens::T_SYMBOL)
				&& ($tokens->isNext(',', '=>', '=') || !$tokens->isNext(...MacroTokens::SIGNIFICANT))
			) {
				// deprecated bare variable name (no leading $) - not a literal-typed declaration we track
				$skip = true;
				$expectingName = false;
			} elseif ($expectingName && !$hasType && $tokens->isCurrent(MacroTokens::T_SYMBOL, '?', 'null', '\\')) {
				$tokens->nextToken();
				while ($tokens->nextValue(MacroTokens::T_SYMBOL, '\\', '|', '[', ']', 'null') !== null) {
				}

				$hasType = true;

				continue;
			} elseif ($expectingName && $tokens->isCurrent(MacroTokens::T_SYMBOL, MacroTokens::T_VARIABLE)) {
				$name = ltrim((string) $tokens->currentValue(), '$');
				$expectingName = false;
			} elseif (!$skip && !$hasType && $name !== null && $tokens->isCurrent('=') && $tokens->depth === 0) {
				$result[$name] = $this->classifyLiteralType($tokens);
			} elseif ($tokens->isCurrent(',', ';') && $tokens->depth === 0) {
				$expectingName = true;
				$hasType = false;
				$skip = false;
				$name = null;
			}
		}

		return $result;
	}

	// Companion to scanTopLevelVarLiterals(), but for {default}: DeclarationScanner's typedDefaults
	// only records a {default} occurrence when it carries an explicit type (see its
	// scanVarDeclarations()), so an untyped `{default $x = 1}` is otherwise invisible to a
	// declared-target-var satisfaction check. This records every top-level {default} name -
	// typed or not - so that gap can be closed without resolving a type.

	/**
	 * @return list<string>
	 */
	private function scanTopLevelDefaultNames(string $value): array
	{
		$names = [];
		$tokens = new MacroTokens($value);

		$expectingName = true;
		$hasType = false;

		while ($tokens->nextToken() !== null) {
			if (
				$expectingName
				&& $tokens->isCurrent(MacroTokens::T_SYMBOL)
				&& ($tokens->isNext(',', '=>', '=') || !$tokens->isNext(...MacroTokens::SIGNIFICANT))
			) {
				// deprecated bare variable name (no leading $)
				$names[] = (string) $tokens->currentValue();
				$expectingName = false;
			} elseif ($expectingName && !$hasType && $tokens->isCurrent(MacroTokens::T_SYMBOL, '?', 'null', '\\')) {
				$tokens->nextToken();
				while ($tokens->nextValue(MacroTokens::T_SYMBOL, '\\', '|', '[', ']', 'null') !== null) {
				}

				$hasType = true;

				continue;
			} elseif ($expectingName && $tokens->isCurrent(MacroTokens::T_SYMBOL, MacroTokens::T_VARIABLE)) {
				$names[] = ltrim((string) $tokens->currentValue(), '$');
				$expectingName = false;
			} elseif ($tokens->isCurrent(',', ';') && $tokens->depth === 0) {
				$expectingName = true;
				$hasType = false;
			}
		}

		return $names;
	}

	// Mirrors DeclarationScanner::scanVarType() (type-then-variable, not name-then-'=' like
	// scanTopLevelVarLiterals()): {varType} carries an explicit type ahead of the variable, so the
	// vendor tag-regex "|" modifier-split trap (e.g. "int|null $x") is handled the same way -
	// by the caller reconcatenating $token->value . $token->modifiers before this ever tokenizes it.

	/**
	 * @return array{string, string}|null
	 */
	private function scanBlockVarType(string $value): ?array
	{
		$tokens = new MacroTokens($value);
		$type = trim($tokens->joinUntil(MacroTokens::T_VARIABLE));
		$variable = $tokens->nextValue(MacroTokens::T_VARIABLE);

		if ($type === '' || $variable === null) {
			return null;
		}

		return [ltrim($variable, '$'), $type];
	}

	private function classifyLiteralType(MacroTokens $tokens): string
	{
		if ($tokens->nextToken(...MacroTokens::SIGNIFICANT) === null) {
			return 'mixed';
		}

		if ($tokens->isCurrent('[')) {
			return 'array';
		}

		$isLastToken = $tokens->isNext(',', ';') || !$tokens->isNext(...MacroTokens::SIGNIFICANT);
		if (!$isLastToken) {
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

		return 'mixed';
	}

	private function dequote(string $value): string
	{
		$length = strlen($value);
		if ($length >= 2 && ($value[0] === "'" || $value[0] === '"') && $value[$length - 1] === $value[0]) {
			return (string) substr($value, 1, -1);
		}

		return $value;
	}

	private function empty(): TemplateFacts
	{
		return new TemplateFacts([], [], [], [], [], [], [], []);
	}

}
