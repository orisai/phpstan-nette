<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use ReflectionClass;
use ReflectionException;
use function dirname;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function realpath;
use function scandir;
use function sha1;
use function sha1_file;
use function sort;
use function strlen;
use function strncmp;
use function substr;
use function substr_compare;

// What identifies a harvested Latte 3 extension's code: its generated PHP lives in its node classes
// as much as in the extension. An extension inside an installed package is its package version; a
// first-party one every PHP file under its class's directory. The walk leaves out PHPStan's tmpDir,
// the discovery and narrowing stores (rewritten by every run), Composer vendor directories, installed packages, dot-directories and nested projects (a
// composer.json of their own). An extension whose directory is the project root, holds the tmpDir
// or exceeds MAX_FILES is salted shallowly - its own directory's PHP files only. An unreadable
// directory is salted as such and reported, never fatal.
final class ExtensionSourceSalt
{

	public const MAX_FILES = 5000;

	private ?string $tmpDir;

	private ?string $projectRoot;

	private ProjectInstalledVersions $installed;

	/** @var list<string> */
	private array $storePaths;

	private bool $resolved = false;

	private ?string $realTmpDir = null;

	/** @var array<string, true> */
	private array $realStorePaths = [];

	private ?string $realProjectRoot = null;

	/**
	 * @param list<string> $storePaths
	 */
	public function __construct(
		?string $tmpDir,
		?string $projectRoot,
		ProjectInstalledVersions $installed,
		array $storePaths = []
	)
	{
		$this->tmpDir = $tmpDir;
		$this->projectRoot = $projectRoot;
		$this->installed = $installed;
		$this->storePaths = $storePaths;
	}

	/**
	 * @param class-string $class
	 * @return array{salt: string, shallow: bool, unreadable: list<string>}
	 */
	public function describe(string $class): array
	{
		try {
			$fileName = (new ReflectionClass($class))->getFileName();
		} catch (ReflectionException $e) {
			return ['salt' => 'unreflectable', 'shallow' => false, 'unreadable' => []];
		}

		if ($fileName === false) {
			return ['salt' => 'internal', 'shallow' => false, 'unreadable' => []];
		}

		$package = $this->installed->packageContaining($fileName);
		if ($package !== null) {
			$salt = 'package:' . $package . '@' . ($this->installed->getVersion($package) ?? '?')
				. '#' . ($this->installed->getReference($package) ?? '?');

			return ['salt' => $salt, 'shallow' => false, 'unreadable' => []];
		}

		$root = realpath(dirname($fileName));
		if ($root === false) {
			return [
				'salt' => 'unreadable',
				'shallow' => false,
				'unreadable' => $this->relativeToProject([dirname($fileName)]),
			];
		}

		if (!$this->isWalkable($root)) {
			return $this->shallow($root);
		}

		$lines = [];
		$unreadable = [];
		$fileCount = 0;
		if (!$this->walk($root, $root, $lines, $unreadable, $fileCount)) {
			return $this->shallow($root);
		}

		return [
			'salt' => 'tree:' . sha1(implode("\n", $lines)),
			'shallow' => false,
			'unreadable' => $this->relativeToProject($unreadable),
		];
	}

	private function isWalkable(string $root): bool
	{
		$this->resolve();

		if ($this->realProjectRoot !== null && $root === $this->realProjectRoot) {
			return false;
		}

		return $this->realTmpDir === null || !self::isWithin($this->realTmpDir, $root);
	}

	/**
	 * @return array{salt: string, shallow: bool, unreadable: list<string>}
	 */
	private function shallow(string $root): array
	{
		$lines = [];
		$unreadable = [];
		$entries = @scandir($root);
		if ($entries === false) {
			$lines[] = "\x1funreadable";
			$unreadable[] = $root;
		} else {
			foreach ($entries as $entry) {
				$path = $root . '/' . $entry;
				if (substr_compare($entry, '.php', -4) === 0 && is_file($path)) {
					$lines[] = $entry . "\x1f" . self::hashFile($path);
				}
			}
		}

		return [
			'salt' => 'shallow:' . sha1(implode("\n", $lines)),
			'shallow' => true,
			'unreadable' => $this->relativeToProject($unreadable),
		];
	}

	/**
	 * @param list<string> $directories
	 * @return list<string>
	 */
	private function relativeToProject(array $directories): array
	{
		$this->resolve();
		if ($this->realProjectRoot === null) {
			return $directories;
		}

		$relative = [];
		foreach ($directories as $directory) {
			$relative[] = $directory !== $this->realProjectRoot && self::isWithin($directory, $this->realProjectRoot)
				? self::relative($this->realProjectRoot, $directory)
				: $directory;
		}

		return $relative;
	}

	/**
	 * @param list<string> $lines
	 * @param list<string> $unreadable
	 */
	private function walk(string $root, string $directory, array &$lines, array &$unreadable, int &$fileCount): bool
	{
		$entries = @scandir($directory);
		if ($entries === false) {
			$lines[] = self::relative($root, $directory) . "\x1funreadable";
			$unreadable[] = $directory;

			return true;
		}

		sort($entries);
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $directory . '/' . $entry;
			if (is_dir($path)) {
				if ($this->isExcludedDirectory($entry, $path)) {
					continue;
				}

				if (!$this->walk($root, $path, $lines, $unreadable, $fileCount)) {
					return false;
				}

				continue;
			}

			if (substr_compare($entry, '.php', -4) !== 0 || !is_file($path)) {
				continue;
			}

			if (++$fileCount > self::MAX_FILES) {
				return false;
			}

			$lines[] = self::relative($root, $path) . "\x1f" . self::hashFile($path);
		}

		return true;
	}

	private function isExcludedDirectory(string $name, string $path): bool
	{
		if ($name[0] === '.' || is_link($path)) {
			return true;
		}

		if (
			is_file($path . '/composer.json')
			|| is_file($path . '/composer/installed.json')
			|| ($name === 'composer' && is_file($path . '/installed.json'))
		) {
			return true;
		}

		$real = realpath($path);
		if ($real === false) {
			return false;
		}

		if ($real === $this->realTmpDir || isset($this->realStorePaths[$real])) {
			return true;
		}

		return $this->installed->packageContaining($real) !== null;
	}

	private function resolve(): void
	{
		if ($this->resolved) {
			return;
		}

		$this->resolved = true;
		$tmpDir = $this->tmpDir !== null ? realpath($this->tmpDir) : false;
		$this->realTmpDir = $tmpDir !== false ? $tmpDir : null;
		$projectRoot = $this->projectRoot !== null ? realpath($this->projectRoot) : false;
		$this->realProjectRoot = $projectRoot !== false ? $projectRoot : null;
		foreach ($this->storePaths as $storePath) {
			$realStorePath = realpath($storePath);
			if ($realStorePath !== false) {
				$this->realStorePaths[$realStorePath] = true;
			}
		}
	}

	private static function isWithin(string $path, string $directory): bool
	{
		return $path === $directory || strncmp($path, $directory . '/', strlen($directory) + 1) === 0;
	}

	private static function relative(string $root, string $path): string
	{
		return $path === $root ? '' : (string) substr($path, strlen($root) + 1);
	}

	private static function hashFile(string $path): string
	{
		$hash = @sha1_file($path);

		return $hash !== false ? $hash : 'unreadable';
	}

}
