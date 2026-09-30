<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Converge;

use function basename;
use function glob;
use function is_dir;
use function ksort;
use function sha1_file;
use const SORT_STRING;

final class StoreDigest
{

	private function __construct()
	{
	}

	/**
	 * @return array{exists: bool, slices: array<string, string>}
	 */
	public static function of(string $storeDirPath): array
	{
		if (!is_dir($storeDirPath)) {
			return ['exists' => false, 'slices' => []];
		}

		$slices = [];
		$files = glob($storeDirPath . '/LatteSlice_*.php');
		foreach ($files === false ? [] : $files as $file) {
			$slices[basename($file)] = (string) sha1_file($file);
		}

		ksort($slices, SORT_STRING);

		return ['exists' => true, 'slices' => $slices];
	}

}
