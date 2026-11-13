<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use function file_get_contents;
use function implode;
use function is_array;
use function is_file;
use function preg_replace;
use function rtrim;
use function sha1;
use function strlen;
use function strncmp;
use function substr;
use function token_get_all;
use const DIRECTORY_SEPARATOR;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;

final class FormsValueExtension implements ResultCacheValueExtension
{

	private const SOURCE_PREFIX = "source\0";

	private const CONTRIBUTORS_PREFIX = "contributors\0";

	private RegistrationIndex $registrationIndex;

	private string $projectRoot;

	/** @var array<string, string> content hash => digest */
	private array $digests = [];

	public function __construct(RegistrationIndex $registrationIndex, string $projectRoot)
	{
		$this->registrationIndex = $registrationIndex;
		$this->projectRoot = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR;
	}

	// A shape reads a file's tokens, never its layout: reformatting or commenting a builder must not
	// re-analyse the files reading its shapes.
	public static function sourceKey(string $file): string
	{
		return self::SOURCE_PREFIX . $file;
	}

	// The contributor set of an interprocedural shape key: a registration appearing in or leaving any
	// file of the universe moves it, which no per-file dependency can see.
	public static function contributorsKey(string $ipKey): string
	{
		return self::CONTRIBUTORS_PREFIX . $ipKey;
	}

	public function getValue(string $key): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		if (self::hasPrefix($key, self::SOURCE_PREFIX)) {
			return $this->sourceDigest(substr($key, strlen(self::SOURCE_PREFIX)));
		}

		if (self::hasPrefix($key, self::CONTRIBUTORS_PREFIX)) {
			return $this->registrationIndex->currentFingerprint(substr($key, strlen(self::CONTRIBUTORS_PREFIX)))
				?? 'unavailable';
		}

		return 'unknown';
	}

	public function keyToResultCache(string $key): string
	{
		if (self::hasPrefix($key, self::SOURCE_PREFIX)) {
			$file = substr($key, strlen(self::SOURCE_PREFIX));
			if (strncmp($file, $this->projectRoot, strlen($this->projectRoot)) === 0) {
				return self::SOURCE_PREFIX . substr($file, strlen($this->projectRoot));
			}
		}

		return $key;
	}

	public function keyFromResultCache(string $storedKey): string
	{
		if (self::hasPrefix($storedKey, self::SOURCE_PREFIX)) {
			$file = substr($storedKey, strlen(self::SOURCE_PREFIX));
			if (
				$file !== ''
				&& $file[0] !== '/'
				&& $file[0] !== '\\'
				&& !self::hasPrefix($file, 'phar://')
				&& substr($file, 1, 1) !== ':'
			) {
				return self::SOURCE_PREFIX . $this->projectRoot . $file;
			}
		}

		return $storedKey;
	}

	private static function hasPrefix(string $value, string $prefix): bool
	{
		return strncmp($value, $prefix, strlen($prefix)) === 0;
	}

	private function sourceDigest(string $file): string
	{
		$source = is_file($file) ? @file_get_contents($file) : false;
		if ($source === false) {
			return 'missing';
		}

		$contentHash = sha1($source);
		if (isset($this->digests[$contentHash])) {
			return $this->digests[$contentHash];
		}

		// Silenced: a fixture written for a newer PHP than the one running the analysis must not
		// print tokeniser notices; it is digested as whatever tokens it yields.
		$parts = [];
		foreach (@token_get_all($source) as $token) {
			$id = is_array($token) ? $token[0] : -1;
			$text = is_array($token) ? $token[1] : $token;

			// PHP 7.4 lexes a PHP 8 attribute as a `#` comment; it may be a shape input, so it stays.
			if ($id === T_WHITESPACE || ($id === T_COMMENT && strncmp($text, '#[', 2) !== 0)) {
				continue;
			}

			if ($id === T_DOC_COMMENT) {
				// Doc comments carry @form-* tags and PHPDoc types, their layout carries nothing.
				$text = (string) preg_replace('~\s+~', ' ', $text);
			}

			$parts[] = $id . "\x1f" . $text;
		}

		return $this->digests[$contentHash] = sha1(implode("\x1e", $parts));
	}

}
