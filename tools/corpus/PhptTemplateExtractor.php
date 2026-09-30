<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Tools\Corpus;

use function array_pop;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function ltrim;
use function preg_match;
use function preg_replace;
use function strrpos;
use function substr;
use function substr_count;
use function token_get_all;
use const T_AND_EQUAL;
use const T_ARRAY;
use const T_AS;
use const T_COALESCE_EQUAL;
use const T_COMMENT;
use const T_CONCAT_EQUAL;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DIV_EQUAL;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_ARROW;
use const T_DOUBLE_COLON;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_END_HEREDOC;
use const T_FN;
use const T_FUNCTION;
use const T_MINUS_EQUAL;
use const T_MOD_EQUAL;
use const T_MUL_EQUAL;
use const T_OR_EQUAL;
use const T_PLUS_EQUAL;
use const T_POW_EQUAL;
use const T_SL_EQUAL;
use const T_SR_EQUAL;
use const T_START_HEREDOC;
use const T_STRING;
use const T_VARIABLE;
use const T_WHITESPACE;
use const T_XOR_EQUAL;

final class PhptTemplateExtractor
{

	public const CALLS = ['compile', 'render', 'renderToString', 'parse', 'createTemplate'];

	private const EXCEPTION_ASSERTIONS = ['exception', 'throws', 'error'];

	private const COMPOUND_ASSIGNMENTS = [
		T_AND_EQUAL,
		T_COALESCE_EQUAL,
		T_CONCAT_EQUAL,
		T_DIV_EQUAL,
		T_MINUS_EQUAL,
		T_MOD_EQUAL,
		T_MUL_EQUAL,
		T_OR_EQUAL,
		T_PLUS_EQUAL,
		T_POW_EQUAL,
		T_SL_EQUAL,
		T_SR_EQUAL,
		T_XOR_EQUAL,
	];

	/**
	 * @return list<ExtractedTemplate>
	 */
	public function extract(string $code): array
	{
		$tokens = $this->tokenize($code);
		$bindings = $this->collectBindings($tokens);

		$scopes = $this->assertionScopes($tokens);

		/** @var array<int, ExtractedTemplate> $found */
		$found = [];
		$loaderEntries = $this->collectLoaderEntries($tokens, $bindings, $scopes, $found);

		foreach ($tokens as $i => [$id, $text, $line]) {
			if (
				$id !== T_STRING
				|| !in_array($text, self::CALLS, true)
				|| !in_array($tokens[$i - 1][1] ?? null, ['->', '?->'], true)
				|| ($tokens[$i + 1][1] ?? null) !== '('
			) {
				continue;
			}

			$start = $i + 2;
			if (($tokens[$start][0] ?? null) === T_STRING && ($tokens[$start + 1][1] ?? null) === ':') {
				$start += 2;
			}

			$end = $this->expressionEnd($tokens, $start);
			$resolved = $this->resolve($tokens, $bindings, $start, $end);
			if ($resolved === null) {
				continue;
			}

			$expects = $scopes[$i];
			if (isset($loaderEntries[$resolved['content']])) {
				$rendered = $this->renderedLoaderEntry($loaderEntries[$resolved['content']], $i);
				if ($rendered !== null && $found[$rendered]->expects === null) {
					$found[$rendered]->expects = $expects;
				}

				continue;
			}

			$position = $resolved['position'];
			if (isset($found[$position])) {
				$found[$position]->expects ??= $expects;

				continue;
			}

			$found[$position] = new ExtractedTemplate(
				$resolved['content'],
				$resolved['line'],
				$line,
				$text,
				null,
				$resolved['variable'],
				$expects,
			);
		}

		ksort($found);

		$templates = [];
		foreach ($found as $template) {
			$templates[] = $template;
		}

		return $templates;
	}

	/**
	 * @return list<array{int|null, string, int}>
	 */
	private function tokenize(string $code): array
	{
		$tokens = [];
		$line = 1;
		foreach (token_get_all($code) as $token) {
			[$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
			if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
				$tokens[] = [$id, $text, $line];
			}

			$line += substr_count($text, "\n");
		}

		return $tokens;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 * @return array<string, list<array{index: int, value: array{content: string, position: int, line: int}|null}>>
	 */
	private function collectBindings(array $tokens): array
	{
		$bindings = [];

		foreach ($tokens as $i => [$id]) {
			if ($id === T_VARIABLE) {
				$next = $tokens[$i + 1] ?? [null, '', 0];
				if ($next[1] === '=') {
					$start = $i + 2;
					$end = $this->expressionEnd($tokens, $start);
					$content = $this->literal($tokens, $start, $end);
					$bindings[$tokens[$i][1]][] = ['index' => $i, 'value' => $content === null ? null : [
						'content' => $content,
						'position' => $start,
						'line' => $tokens[$start][2],
					]];
				} elseif (in_array($next[0], self::COMPOUND_ASSIGNMENTS, true)) {
					$bindings[$tokens[$i][1]][] = ['index' => $i, 'value' => null];
				}
			} elseif ($id === T_AS) {
				$j = $this->skipReference($tokens, $i + 1);
				if (($tokens[$j][0] ?? null) === T_VARIABLE) {
					$bindings[$tokens[$j][1]][] = ['index' => $j, 'value' => null];
					if (($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW) {
						$k = $this->skipReference($tokens, $j + 2);
						if (($tokens[$k][0] ?? null) === T_VARIABLE) {
							$bindings[$tokens[$k][1]][] = ['index' => $k, 'value' => null];
						}
					}
				}
			} elseif ($id === T_FUNCTION || $id === T_FN) {
				$j = $this->skipReference($tokens, $i + 1);
				if (($tokens[$j][0] ?? null) === T_STRING) {
					$j++;
				}

				if (($tokens[$j][1] ?? null) !== '(') {
					continue;
				}

				$close = $this->expressionEnd($tokens, $j + 1);
				while (($tokens[$close][1] ?? null) === ',') {
					$close = $this->expressionEnd($tokens, $close + 1);
				}

				for ($k = $j + 1; $k < $close; $k++) {
					if ($tokens[$k][0] === T_VARIABLE) {
						$bindings[$tokens[$k][1]][] = ['index' => $k, 'value' => null];
					}
				}
			}
		}

		return $bindings;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 * @param array<string, list<array{index: int, value: array{content: string, position: int, line: int}|null}>> $bindings
	 * @param array<int, array{assertion: string, class: string|null, message: string|null}|null> $scopes
	 * @param array<int, ExtractedTemplate> $found
	 * @return array<string, list<array{loader: int|null, position: int|null}>>
	 */
	private function collectLoaderEntries(array $tokens, array $bindings, array $scopes, array &$found): array
	{
		$entries = [];
		$dynamic = false;
		foreach ($tokens as $i => [, $text, $line]) {
			$separator = strrpos($text, '\\');
			$name = $separator === false ? $text : substr($text, $separator + 1);
			if ($name !== 'StringLoader' || ($tokens[$i + 1][1] ?? null) !== '(') {
				continue;
			}

			$open = $i + 2;
			if (($tokens[$open][0] ?? null) === T_ARRAY && ($tokens[$open + 1][1] ?? null) === '(') {
				$open++;
			} elseif (($tokens[$open][1] ?? null) !== '[') {
				$dynamic = $dynamic || ($tokens[$open][1] ?? null) !== ')';

				continue;
			}

			$start = $open + 1;
			while (isset($tokens[$start]) && !in_array($tokens[$start][1], [']', ')'], true)) {
				$end = $this->expressionEnd($tokens, $start);
				$arrow = $this->arrowIn($tokens, $start, $end);
				if ($arrow !== null) {
					$this->addLoaderEntry($tokens, $bindings, $scopes, $found, $entries, $i, $start, $arrow, $end);
				}

				if (($tokens[$end][1] ?? null) !== ',') {
					break;
				}

				$start = $end + 1;
			}
		}

		if (!$dynamic) {
			return $entries;
		}

		foreach ($tokens as $arrow => [$id]) {
			if (
				$id !== T_DOUBLE_ARROW
				|| ($tokens[$arrow - 1][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING
				|| !in_array($tokens[$arrow - 2][1] ?? null, ['[', '(', ','], true)
			) {
				continue;
			}

			$end = $this->expressionEnd($tokens, $arrow + 1);
			$value = $this->literal($tokens, $arrow + 1, $end);
			if ($value !== null && preg_match('~\{|n:~', $value) === 1) {
				$this->addLoaderEntry($tokens, $bindings, $scopes, $found, $entries, null, $arrow - 1, $arrow, $end);
			}
		}

		return $entries;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 * @param array<string, list<array{index: int, value: array{content: string, position: int, line: int}|null}>> $bindings
	 * @param array<int, array{assertion: string, class: string|null, message: string|null}|null> $scopes
	 * @param array<int, ExtractedTemplate> $found
	 * @param array<string, list<array{loader: int|null, position: int|null}>> $entries
	 */
	private function addLoaderEntry(
		array $tokens,
		array $bindings,
		array $scopes,
		array &$found,
		array &$entries,
		?int $loader,
		int $start,
		int $arrow,
		int $end
	): void
	{
		$key = $this->literal($tokens, $start, $arrow);
		if ($key === null) {
			return;
		}

		$resolved = $this->resolve($tokens, $bindings, $arrow + 1, $end);
		$entries[$key][] = ['loader' => $loader, 'position' => $resolved === null ? null : $resolved['position']];
		if ($resolved === null || isset($found[$resolved['position']])) {
			return;
		}

		$found[$resolved['position']] = new ExtractedTemplate(
			$resolved['content'],
			$resolved['line'],
			$tokens[$loader ?? $arrow][2],
			'StringLoader',
			$key,
			$resolved['variable'],
			$scopes[$arrow],
		);
	}

	/**
	 * @param list<array{loader: int|null, position: int|null}> $entries
	 */
	private function renderedLoaderEntry(array $entries, int $call): ?int
	{
		$rendered = null;
		foreach ($entries as $entry) {
			if (
				$entry['loader'] !== null
				&& $entry['loader'] < $call
				&& ($rendered === null || $entry['loader'] > $rendered['loader'])
			) {
				$rendered = $entry;
			}
		}

		return $rendered === null ? null : $rendered['position'];
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 * @param array<string, list<array{index: int, value: array{content: string, position: int, line: int}|null}>> $bindings
	 * @return array{content: string, position: int, line: int, variable: string|null}|null
	 */
	private function resolve(array $tokens, array $bindings, int $start, int $end): ?array
	{
		$content = $this->literal($tokens, $start, $end);
		if ($content !== null) {
			return ['content' => $content, 'position' => $start, 'line' => $tokens[$start][2], 'variable' => null];
		}

		if ($end !== $start + 1 || $tokens[$start][0] !== T_VARIABLE) {
			return null;
		}

		$variable = $tokens[$start][1];
		$nearest = null;
		foreach ($bindings[$variable] ?? [] as $binding) {
			if ($binding['index'] >= $start) {
				break;
			}

			$nearest = $binding;
		}

		if ($nearest === null || $nearest['value'] === null) {
			return null;
		}

		return $nearest['value'] + ['variable' => $variable];
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 */
	private function literal(array $tokens, int $start, int $end): ?string
	{
		if ($start >= $end) {
			return null;
		}

		$source = '';
		$expectOperand = true;
		for ($i = $start; $i < $end; $i++) {
			[$id, $text] = $tokens[$i];
			if (!$expectOperand) {
				if ($text !== '.') {
					return null;
				}

				$source .= ' . ';
				$expectOperand = true;

				continue;
			}

			if ($id === T_CONSTANT_ENCAPSED_STRING) {
				$source .= $text;
			} elseif ($id === T_START_HEREDOC) {
				$source .= $text;
				if (($tokens[$i + 1][0] ?? null) === T_ENCAPSED_AND_WHITESPACE) {
					$source .= $tokens[++$i][1];
				}

				if (($tokens[$i + 1][0] ?? null) !== T_END_HEREDOC || $i + 1 >= $end) {
					return null;
				}

				$source .= $tokens[++$i][1] . "\n";
			} else {
				return null;
			}

			$expectOperand = false;
		}

		if ($expectOperand) {
			return null;
		}

		// Only string-literal and interpolation-free heredoc/nowdoc tokens reach here, so nothing executes.
		$value = eval('return ' . $source . ';');

		return is_string($value) ? $value : null;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 */
	private function expressionEnd(array $tokens, int $start): int
	{
		$depth = 0;
		$count = count($tokens);
		for ($i = $start; $i < $count; $i++) {
			[$id, $text] = $tokens[$i];
			if (in_array($text, ['(', '[', '{'], true) || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$depth++;
			} elseif (in_array($text, [')', ']', '}'], true)) {
				if ($depth === 0) {
					return $i;
				}

				$depth--;
			} elseif ($depth === 0 && ($text === ',' || $text === ';')) {
				return $i;
			}
		}

		return $count;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 */
	private function arrowIn(array $tokens, int $start, int $end): ?int
	{
		for ($i = $start; $i < $end; $i++) {
			if ($tokens[$i][0] === T_DOUBLE_ARROW) {
				return $i;
			}

			if (in_array($tokens[$i][1], ['(', '['], true)) {
				return null;
			}
		}

		return null;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 */
	private function skipReference(array $tokens, int $index): int
	{
		return ($tokens[$index][1] ?? null) === '&' ? $index + 1 : $index;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 * @return array<int, array{assertion: string, class: string|null, message: string|null}|null>
	 */
	private function assertionScopes(array $tokens): array
	{
		$scopes = [];
		$stack = [];
		foreach ($tokens as $i => [, $text]) {
			$current = $stack === [] ? null : $stack[count($stack) - 1];
			$scopes[$i] = $current;
			if ($text === '(') {
				$stack[] = $this->assertionAt($tokens, $i) ?? $current;
			} elseif ($text === ')') {
				array_pop($stack);
			}
		}

		return $scopes;
	}

	/**
	 * @param list<array{int|null, string, int}> $tokens
	 * @return array{assertion: string, class: string|null, message: string|null}|null
	 */
	private function assertionAt(array $tokens, int $paren): ?array
	{
		$method = $tokens[$paren - 1] ?? null;
		$class = $tokens[$paren - 3] ?? null;
		if (
			$method === null
			|| $class === null
			|| $method[0] !== T_STRING
			|| ($tokens[$paren - 2][0] ?? null) !== T_DOUBLE_COLON
			|| !in_array($method[1], self::EXCEPTION_ASSERTIONS, true)
		) {
			return null;
		}

		$separator = strrpos($class[1], '\\');
		if (($separator === false ? $class[1] : substr($class[1], $separator + 1)) !== 'Assert') {
			return null;
		}

		$expects = ['assertion' => $method[1], 'class' => null, 'message' => null];
		$callableEnd = $this->expressionEnd($tokens, $paren + 1);
		if (($tokens[$callableEnd][1] ?? null) !== ',') {
			return $expects;
		}

		$classEnd = $this->expressionEnd($tokens, $callableEnd + 1);
		$raw = $this->literal($tokens, $callableEnd + 1, $classEnd);
		if ($raw === null) {
			$raw = '';
			for ($i = $callableEnd + 1; $i < $classEnd; $i++) {
				$raw .= $tokens[$i][1];
			}
		}

		$raw = ltrim(preg_replace('~::class$~', '', $raw) ?? $raw, '\\');
		$expects['class'] = $raw === '' ? null : $raw;
		if (($tokens[$classEnd][1] ?? null) === ',') {
			$expects['message'] = $this->literal($tokens, $classEnd + 1, $this->expressionEnd($tokens, $classEnd + 1));
		}

		return $expects;
	}

}
