<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use InvalidArgumentException;
use Latte\Compiler;
use Latte\MacroNode;
use Latte\Macros\MacroSet;
use Latte\PhpWriter;
use function array_slice;
use function array_unshift;
use function implode;
use function is_array;
use function preg_match;
use function preg_replace;
use function rtrim;
use function strpos;
use function substr;
use function substr_count;
use function token_get_all;
use const T_WHITESPACE;

// Replica of h4kuna\Gettext\Macros\Gettext (futurerockstars/gettext-latte, PHP < 8 only) together
// with h4kuna\Template\LattePhpTokenizer::toArray(): the {_}/{g_}/{ng_}/{dg_}/{dng_} family the
// harvested-macros tests compile natively, with the vendor's argument handling kept verbatim.
class FixtureGettextMacros extends MacroSet
{

	private const GETTEXT = 'ettext';

	private const FUNCTIONS = ['g' => 1, 'ng' => 3, 'dg' => 2, 'dng' => 4];

	private ?string $function = null;

	private bool $plural = false;

	private int $params = 0;

	public static function install(Compiler $compiler): self
	{
		$me = new static($compiler);
		$me->addMacro('_', [$me, 'unknown']);
		foreach (self::FUNCTIONS as $prefix => $count) {
			$me->addMacro($prefix . '_', [$me, 'ettext']);
		}

		return $me;
	}

	public function unknown(MacroNode $node, PhpWriter $writer): string
	{
		$node->args = $this->detectFunction($node->args);

		return $this->ettext($node, $writer);
	}

	public function ettext(MacroNode $node, PhpWriter $writer): string
	{
		$this->setFunction($node->name);
		$args = self::splitArgs($node->args);
		$argsGettext = $this->gettextArgs($args);

		$out = $this->function . '(' . implode(', ', $argsGettext) . ')';
		$key = (int) (substr((string) $this->function, 0, 1) === 'd');
		$diff = -1 * substr_count($args[$key], '%s');
		if ($diff !== 0) {
			$out = 'sprintf(' . $out . ', ' . implode(', ', array_slice($args, $diff)) . ')';
		}

		$this->function = null;

		return $writer->write('echo %modify(' . $out . ')');
	}

	/**
	 * @param list<string> $args
	 * @return list<string>
	 */
	private function gettextArgs(array $args): array
	{
		$argsGettext = array_slice($args, 0, $this->params);
		if (!$this->plural) {
			return $argsGettext;
		}

		$key = (int) ($this->function === 'dngettext');
		array_unshift($argsGettext, $argsGettext[$key]);
		$n = $argsGettext[0];
		$argsGettext[0] = $argsGettext[1];
		$argsGettext[1] = $n;

		foreach ($args as $param) {
			if (preg_match('/plural/i', $param) === 1) {
				$argsGettext[2 + $key] = $param;
			}
		}

		if (preg_match('/abs/i', $argsGettext[2 + $key]) === 1) {
			$argsGettext[2 + $key] = 'abs(' . $argsGettext[2 + $key] . ')';
		}

		return $argsGettext;
	}

	private function setFunction(string $name): void
	{
		if ($this->function !== null) {
			return;
		}

		$prefix = rtrim($name, '_');
		$this->function = $prefix . self::GETTEXT;
		$this->params = self::FUNCTIONS[$prefix];
		$this->plural = strpos($prefix, 'n') !== false;
		if ($this->plural) {
			--$this->params;
		}
	}

	private function detectFunction(string $args): string
	{
		if ($this->function !== null) {
			return $args;
		}

		if (preg_match('/(.*)(?:"|\')/U', $args, $find) === 1 && isset(self::FUNCTIONS[$find[1] . 'g'])) {
			$this->setFunction($find[1] . 'g_');

			return $find[1] !== '' ? (string) preg_replace('/^' . $find[1] . '/', '', $args) : $args;
		}

		throw new InvalidArgumentException('Unsupported translate macro: ' . $args);
	}

	/**
	 * @return list<string>
	 */
	private static function splitArgs(string $args): array
	{
		$result = [];
		$param = '';
		$inner = 0;
		foreach (array_slice(token_get_all('<?php ord(' . $args . ');'), 3, -2) as $token) {
			if (is_array($token)) {
				if ($token[0] !== T_WHITESPACE) {
					$param .= $token[1];
				}

				continue;
			}

			if ($token === '(') {
				++$inner;
			} elseif ($inner > 0 && $token === ')') {
				--$inner;
			} elseif ($token === ',' && $inner === 0) {
				$result[] = $param;
				$param = '';

				continue;
			}

			$param .= $token;
		}

		if ($param !== '') {
			$result[] = $param;
		}

		return $result;
	}

}
