<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use function array_keys;

// Shared by CustomsHarvester (harvested filters/functions) and Version\Latte2\CaseMismatchScanner
// (built-in Defaults filters/functions): Latte's own Defaults deliberately registers some filters
// under two spellings of the same lowercase name (dataStream/datastream, stripTags/striptags, ...)
// - a naive lower-to-orig flip would pick one arbitrarily and false-positive a case-mismatch
// diagnostic against real template usage of the other, equally-valid spelling. An ambiguous
// lowercase key is dropped entirely instead of guessed.
final class OriginalNameCollisionMap
{

	/**
	 * @param array<string, string> $origToLower spelling => lowercase
	 * @return array<string, string> lowercase => spelling
	 */
	public static function build(array $origToLower): array
	{
		$byLower = [];
		$ambiguous = [];

		foreach ($origToLower as $orig => $lower) {
			if (isset($byLower[$lower]) && $byLower[$lower] !== $orig) {
				$ambiguous[$lower] = true;

				continue;
			}

			$byLower[$lower] = $orig;
		}

		foreach (array_keys($ambiguous) as $lower) {
			unset($byLower[$lower]);
		}

		return $byLower;
	}

}
