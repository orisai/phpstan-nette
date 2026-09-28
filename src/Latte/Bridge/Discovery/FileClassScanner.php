<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use Nette\Utils\FileSystem;
use Throwable;
use function array_key_exists;
use function count;
use function defined;
use function is_array;
use function token_get_all;
use const T_CLASS;
use const T_DOUBLE_COLON;
use const T_INTERFACE;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_STRING;
use const T_TRAIT;
use const T_WHITESPACE;

// Two questions off one scan. A read-set file is one class-like per file (the walk collects them
// from reflection getFileName()), so the FIRST declaration is taken as that file's identity; the
// pre-analysis universe instead needs EVERY declaration, because a class moved into a file that
// already declares one is still a class this configuration analyses. Token-based, never a regex
// over raw text, so comments and string literals cannot produce a false name.
final class FileClassScanner
{

	/** @var array<string, list<string>> */
	private array $memo = [];

	public function firstClassLikeIn(string $file): ?string
	{
		return $this->allClassLikesIn($file)[0] ?? null;
	}

	/**
	 * @return list<string>
	 */
	public function allClassLikesIn(string $file): array
	{
		if (array_key_exists($file, $this->memo)) {
			return $this->memo[$file];
		}

		return $this->memo[$file] = self::scan($file);
	}

	/**
	 * @return list<string>
	 */
	private static function scan(string $file): array
	{
		try {
			$contents = FileSystem::read($file);
		} catch (Throwable $e) {
			return [];
		}

		try {
			$tokens = token_get_all($contents);
		} catch (Throwable $e) {
			return [];
		}

		$names = [];
		$namespace = '';
		$count = count($tokens);

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (!is_array($token)) {
				continue;
			}

			if ($token[0] === T_NAMESPACE) {
				$namespace = self::namespaceAt($tokens, $count, $i + 1);

				continue;
			}

			if ($token[0] !== T_CLASS && $token[0] !== T_INTERFACE && $token[0] !== T_TRAIT) {
				continue;
			}

			// `Foo::class` and `new class` both surface a T_CLASS token without declaring one.
			$previous = self::previousMeaningful($tokens, $i - 1);
			if (
				$previous !== null
				&& is_array($previous)
				&& (
					$previous[0] === T_DOUBLE_COLON
					|| $previous[0] === T_NEW
				)
			) {
				continue;
			}

			$name = self::nextName($tokens, $count, $i + 1);
			if ($name === null) {
				continue;
			}

			$names[] = $namespace === '' ? $name : $namespace . '\\' . $name;
		}

		return $names;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function namespaceAt(array $tokens, int $count, int $start): string
	{
		$namespace = '';
		for ($i = $start; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token === ';' || $token === '{') {
				break;
			}

			if (!is_array($token)) {
				continue;
			}

			// PHP >= 8.0 lexes `Foo\Bar` as one T_NAME_QUALIFIED token; 7.4 spells it out as
			// T_STRING + T_NS_SEPARATOR pieces.
			if ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR) {
				$namespace .= $token[1];
			} elseif (defined('T_NAME_QUALIFIED') && $token[0] === T_NAME_QUALIFIED) {
				$namespace .= $token[1];
			}
		}

		return $namespace;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function nextName(array $tokens, int $count, int $start): ?string
	{
		for ($i = $start; $i < $count; $i++) {
			$token = $tokens[$i];
			if (!is_array($token)) {
				return null;
			}

			if ($token[0] === T_WHITESPACE) {
				continue;
			}

			return $token[0] === T_STRING ? $token[1] : null;
		}

		return null;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array{0: int, 1: string, 2: int}|string|null
	 */
	private static function previousMeaningful(array $tokens, int $start)
	{
		for ($i = $start; $i >= 0; $i--) {
			$token = $tokens[$i];
			if (is_array($token) && $token[0] === T_WHITESPACE) {
				continue;
			}

			return $token;
		}

		return null;
	}

}
