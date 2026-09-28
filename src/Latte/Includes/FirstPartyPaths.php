<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function realpath;
use function strlen;
use function strncmp;
use const DIRECTORY_SEPARATOR;

// The one first-party boundary every Latte consumer shares: which files may be walked, which
// templates the orphan check may judge, which classes may be renderers. Bases are resolved once -
// containment is asked per class and per ancestor hop, so a realpath per question would be paid
// thousands of times for a config value that cannot change during a run.
final class FirstPartyPaths
{

	/** @var list<string> */
	private array $bases;

	/**
	 * @param list<string> $paths
	 */
	public function __construct(array $paths)
	{
		$bases = [];
		foreach ($paths as $path) {
			$real = realpath($path);
			$bases[] = $real === false ? $path : $real;
		}

		$this->bases = $bases;
	}

	public function contains(string $file): bool
	{
		$real = realpath($file);
		$file = $real === false ? $file : $real;

		foreach ($this->bases as $base) {
			if ($file === $base || strncmp($file, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0) {
				return true;
			}
		}

		return false;
	}

}
