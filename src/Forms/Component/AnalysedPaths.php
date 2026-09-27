<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use function realpath;
use function strlen;
use function strncmp;
use const DIRECTORY_SEPARATOR;

final class AnalysedPaths
{

	/** @var list<string> */
	private array $paths;

	/**
	 * The declared paths with every symlink resolved once, so the containment test below is a string
	 * comparison and not a syscall per path per question. It is asked once per registering call site
	 * now, which the per-question realpath sweep it replaced would have made a hot loop.
	 *
	 * @var list<string>
	 */
	private array $bases;

	/** @var array<string, bool> */
	private array $memo = [];

	/**
	 * @param list<string> $paths
	 */
	public function __construct(array $paths)
	{
		$this->paths = $paths;

		$bases = [];
		foreach ($paths as $path) {
			$real = realpath($path);
			$bases[] = $real === false ? $path : $real;
		}

		$this->bases = $bases;
	}

	/**
	 * @return list<string>
	 */
	public function all(): array
	{
		return $this->paths;
	}

	/**
	 * Whether a universe was declared at all. With none there is no line to draw, so no file may be
	 * called foreign — the fail-safe reading for a consumer (or a test harness) that wired no paths.
	 */
	public function isConfigured(): bool
	{
		return $this->paths !== [];
	}

	public function isAnalysed(string $file): bool
	{
		return $this->memo[$file] ??= $this->contains($file);
	}

	private function contains(string $file): bool
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
