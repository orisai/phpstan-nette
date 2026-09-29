<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use function dirname;
use function glob;
use function sort;
use function strlen;
use function substr;
use const GLOB_BRACE;

// The templates whose extracted facts are pinned as JSON under tests/Unit/Latte/Fixtures/__facts__:
// the compile-snapshot fixtures, the include-graph fixture trees, the LatteForms fixtures and the
// version-parity fixtures, each with the project-relative path both adapters resolve include
// targets against.
final class FactsFixtures
{

	private const SETS = [
		['tests/Unit/Latte/Fixtures', 'fixtures', ''],
		['tests/Unit/Latte/Includes/Fixtures', '', 'includes/'],
		['tests/Unit/LatteForms/Fixtures', 'latteforms', 'latteforms/'],
		['tests/Unit/Latte/Version/Fixtures', 'version', 'version/'],
	];

	private function __construct()
	{
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function all(): iterable
	{
		$root = dirname(__DIR__, 2);
		foreach (self::SETS as [$directory, $relativePrefix, $jsonPrefix]) {
			$base = $root . '/' . $directory;
			$paths = (array) glob($base . '/{*,*/*}.latte', GLOB_BRACE);
			sort($paths);
			foreach ($paths as $path) {
				$path = (string) $path;
				$inSet = (string) substr($path, strlen($base) + 1);
				$relativePath = $relativePrefix === '' ? $inSet : $relativePrefix . '/' . $inSet;
				$jsonPath = $root . '/tests/Unit/Latte/Fixtures/__facts__/' . $jsonPrefix
					. (string) substr($inSet, 0, -strlen('.latte')) . '.json';

				yield $relativePath => [$path, $relativePath, $jsonPath];
			}
		}
	}

}
