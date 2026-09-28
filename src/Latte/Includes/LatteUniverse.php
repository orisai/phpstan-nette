<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use LogicException;
use Nette\Utils\Finder;
use OriPhpstan\Nette\Latte\Compile\ProjectRelativePath;
use function in_array;
use function is_dir;
use function is_file;
use function sha1;
use function sha1_file;
use function sort;
use function strlen;
use function substr_compare;

final class LatteUniverse
{

	/** @var list<string> */
	private array $paths;

	private string $projectRoot;

	/** @var list<string>|null */
	private ?array $files = null;

	/** @var array<string, string>|null */
	private ?array $contentHashes = null;

	/**
	 * @param list<string> $paths
	 */
	public function __construct(array $paths, string $projectRoot)
	{
		$this->paths = $paths;
		$this->projectRoot = $projectRoot;
	}

	/**
	 * @return list<string>
	 */
	public function files(): array
	{
		if ($this->files === null) {
			$this->files = $this->findLatteFiles();
		}

		return $this->files;
	}

	/**
	 * @return array<string, string>
	 */
	public function contentHashes(): array
	{
		if ($this->contentHashes === null) {
			$hashes = [];
			foreach ($this->files() as $file) {
				$hash = sha1_file($file);
				$hashes[$file] = $hash === false ? sha1($file) : $hash;
			}

			$this->contentHashes = $hashes;
		}

		return $this->contentHashes;
	}

	public function relativePath(string $file): string
	{
		return ProjectRelativePath::relativize($this->projectRoot, $file);
	}

	public function projectRoot(): string
	{
		return $this->projectRoot;
	}

	public function contains(string $file): bool
	{
		return in_array($file, $this->files(), true);
	}

	/**
	 * @return list<string>
	 */
	private function findLatteFiles(): array
	{
		$files = [];
		foreach ($this->paths as $path) {
			if (is_file($path)) {
				if (substr_compare($path, '.latte', -6) === 0) {
					$files[] = $path;
				}

				continue;
			}

			if (!is_dir($path)) {
				continue;
			}

			foreach (Finder::findFiles('*.latte')->from($path) as $pathname => $fileInfo) {
				$files[] = $pathname;
			}
		}

		// A file outside projectRoot makes ProjectRelativePath::relativize()'s str_replace() a
		// silent no-op, collapsing the whole class-name scheme; fail loud instead.
		$prefix = $this->projectRoot . '/';
		foreach ($files as $file) {
			if (substr_compare($file, $prefix, 0, strlen($prefix)) !== 0) {
				throw new LogicException(
					'Latte analysis requires cwd = project root; file ' . $file . ' is outside '
					. $this->projectRoot . ' - run phpstan from the repository root.',
				);
			}
		}

		sort($files);

		return $files;
	}

}
