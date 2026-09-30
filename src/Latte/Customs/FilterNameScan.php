<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use function array_keys;
use function file_get_contents;
use function is_string;
use function preg_match_all;
use function sort;
use const SORT_STRING;

// Every identifier that follows a `|` anywhere in a template: a superset of its filter names on
// both Latte lines (text and PHP operators included), which only costs a loader question per extra
// name.
final class FilterNameScan
{

	/**
	 * @param list<string> $files
	 * @return list<string>
	 */
	public static function scan(array $files): array
	{
		$names = [];
		foreach ($files as $file) {
			$source = @file_get_contents($file);
			if (!is_string($source) || preg_match_all('~\|\s*([A-Za-z_]\w*)~', $source, $matches) < 1) {
				continue;
			}

			foreach ($matches[1] as $name) {
				$names[$name] = true;
			}
		}

		$names = array_keys($names);
		sort($names, SORT_STRING);

		return $names;
	}

}
