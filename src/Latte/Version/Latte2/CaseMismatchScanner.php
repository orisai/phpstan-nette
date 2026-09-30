<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte2;

use Latte\Runtime\Defaults;
use Latte\Token;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\OriginalNameCollisionMap;
use function array_keys;
use function preg_match_all;
use function preg_replace;
use function strtolower;

// Latte 3 made filter AND function name resolution case-sensitive (2.11's FilterExecutor/
// Compiler::$functions are both case-insensitive: lookup lowercases before matching). A template
// calling a registered name with the wrong case works today and breaks on a Latte-3 upgrade - this
// scans for that independently of the compile pipeline. Vendor's own case-mismatch trigger_error
// only ever covers the stock 7 Defaults functions and the checkUrl filter (LatteCompiler never
// calls setFilters(), so the general filter branch is dead code) - this scanner is the only
// mechanism with full custom-name coverage, whether or not LatteCompiler's containment happens to
// catch the vendor warning too.
final class CaseMismatchScanner
{

	/** @var array<string, string> */
	private array $filterOriginalNames;

	/** @var array<string, string> */
	private array $functionOriginalNames;

	public function __construct(?HarvestedCustoms $harvested = null)
	{
		$harvested ??= HarvestedCustoms::empty();
		$defaults = new Defaults();

		$this->filterOriginalNames = self::merge(
			OriginalNameCollisionMap::build(self::origToLower($defaults->getFilters())),
			$harvested->getFilterOriginalNames(),
		);
		$this->functionOriginalNames = self::merge(
			OriginalNameCollisionMap::build(self::origToLower($defaults->getFunctions())),
			$harvested->getFunctionOriginalNames(),
		);
	}

	/**
	 * @param array<string, callable(mixed...): mixed> $names
	 * @return array<string, string> spelling => lowercase
	 */
	private static function origToLower(array $names): array
	{
		$map = [];
		foreach (array_keys($names) as $name) {
			$map[$name] = strtolower($name);
		}

		return $map;
	}

	/**
	 * @param array<Token> $tokens
	 * @return list<Diagnostic>
	 */
	public function scan(array $tokens): array
	{
		$diagnostics = [];

		foreach ($tokens as $token) {
			// n:attribute expressions live in HTML_ATTRIBUTE_BEGIN tokens, a distinct Token::type
			// from MACRO_TAG - their expression text never reaches the regexes below (accepted,
			// documented gap).
			if ($token->type !== Token::MACRO_TAG) {
				continue;
			}

			$text = self::stripStringLiterals($token->text);
			$this->scanFilters($text, $token->line, $diagnostics);
			$this->scanFunctions($text, $token->line, $diagnostics);
		}

		return $diagnostics;
	}

	/**
	 * @param list<Diagnostic> $diagnostics
	 */
	private function scanFilters(string $text, int $line, array &$diagnostics): void
	{
		// A single `|` not adjacent to another `|` (excludes the `||` logical-or operator)
		// followed by an identifier.
		if (preg_match_all('/(?<!\|)\|(?!\|)\s*([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches) < 1) {
			return;
		}

		foreach ($matches[1] as $name) {
			$this->reportIfMismatched(
				$name,
				$this->filterOriginalNames,
				'orisaiNette.latte.filterCaseMismatch',
				'filter',
				$line,
				$diagnostics,
			);
		}
	}

	/**
	 * @param list<Diagnostic> $diagnostics
	 */
	private function scanFunctions(string $text, int $line, array &$diagnostics): void
	{
		// An identifier immediately followed by '(' that is not itself preceded by '$', '->',
		// '::' or '\' (excludes variable-callable invocations, method calls, static calls and
		// namespaced calls).
		if (preg_match_all('/(?<![\w$>:\\\\])([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $matches) < 1) {
			return;
		}

		foreach ($matches[1] as $name) {
			$this->reportIfMismatched(
				$name,
				$this->functionOriginalNames,
				'orisaiNette.latte.functionCaseMismatch',
				'function',
				$line,
				$diagnostics,
			);
		}
	}

	/**
	 * @param array<string, string> $originalNames
	 * @param list<Diagnostic> $diagnostics
	 */
	private function reportIfMismatched(
		string $name,
		array $originalNames,
		string $identifier,
		string $kind,
		int $line,
		array &$diagnostics
	): void
	{
		$orig = $originalNames[strtolower($name)] ?? null;
		if ($orig === null || $orig === $name) {
			return;
		}

		$diagnostics[] = new Diagnostic(
			$identifier,
			"Latte $kind '$name' differs in case from the registered '$orig' - Latte 2.x resolves "
			. "this case-insensitively, but Latte 3 makes $kind resolution case-sensitive and will "
			. 'break on upgrade.',
			$line,
		);
	}

	/**
	 * @param array<string, string> $builtIn
	 * @param array<string, string> $harvested
	 * @return array<string, string>
	 */
	private static function merge(array $builtIn, array $harvested): array
	{
		foreach ($harvested as $lower => $orig) {
			if (!isset($builtIn[$lower])) {
				$builtIn[$lower] = $orig;
			}
		}

		return $builtIn;
	}

	private static function stripStringLiterals(string $text): string
	{
		$stripped = preg_replace(["/'(?:\\\\.|[^'\\\\])*'/", '/"(?:\\\\.|[^"\\\\])*"/'], '', $text);

		return $stripped ?? $text;
	}

}
