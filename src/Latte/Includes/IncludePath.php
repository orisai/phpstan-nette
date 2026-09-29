<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function array_pop;
use function dirname;
use function explode;
use function implode;

// Project-relative resolution of a static include target against the referring template's path.
final class IncludePath
{

	private function __construct()
	{
	}

	public static function normalize(string $referringPath, string $target): string
	{
		$dir = dirname($referringPath);
		$combined = $dir === '.' ? $target : $dir . '/' . $target;

		$segments = [];
		foreach (explode('/', $combined) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				// Deviates from vendor FileLoader::normalizePath: that keeps an unresolvable
				// leading '..'; here it is dropped, clamping the result to the project root.
				if ($segments !== []) {
					array_pop($segments);
				}

				continue;
			}

			$segments[] = $segment;
		}

		return implode('/', $segments);
	}

}
