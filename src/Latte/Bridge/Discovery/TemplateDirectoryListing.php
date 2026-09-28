<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use function implode;
use function is_dir;
use function scandir;
use function sha1;
use function sort;
use function strlen;
use function substr_compare;
use const SORT_STRING;

// The filesystem read behind the formulas' REVERSE operation, and the only shape of that read the
// PhpFactsCache envelope can re-validate: a directory LISTING, not a per-path stat. A template
// added to (or removed from) an enumerated directory flips no is_file/is_dir result the resolver
// ever probed - it changes the listing - so the listing is what the existence-set records under
// DiscoveryFact::PROBE_LISTING and what the envelope re-digests at load time.
final class TemplateDirectoryListing
{

	public const SUFFIX = '.latte';

	private function __construct()
	{
	}

	/**
	 * @return list<string>
	 */
	public static function names(string $directory): array
	{
		// A missing directory and an empty one are the same answer on purpose: creating an empty
		// directory offers no view, and the first template written into it moves the digest.
		if (!is_dir($directory)) {
			return [];
		}

		$entries = scandir($directory);
		if ($entries === false) {
			return [];
		}

		$suffixLength = strlen(self::SUFFIX);

		$names = [];
		foreach ($entries as $entry) {
			if (strlen($entry) > $suffixLength && substr_compare($entry, self::SUFFIX, -$suffixLength) === 0) {
				$names[] = $entry;
			}
		}

		sort($names, SORT_STRING);

		return $names;
	}

	public static function digest(string $directory): string
	{
		return self::digestOf(self::names($directory));
	}

	// A digest, not the name list: an envelope field growing with the size of every enumerated
	// directory would dwarf the facts it validates, and equality is all the freshness check asks.

	/**
	 * @param list<string> $names
	 */
	public static function digestOf(array $names): string
	{
		return sha1(implode("\0", $names));
	}

}
