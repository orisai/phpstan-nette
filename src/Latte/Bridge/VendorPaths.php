<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use Composer\InstalledVersions;
use OriPhpstan\Nette\Latte\Includes\FirstPartyPaths;
use function dirname;
use function realpath;
use function strlen;
use function strncmp;
use const DIRECTORY_SEPARATOR;

// Install roots of every Composer package except each installation's own root package - where a
// class is located, not how the vendor directory happens to be named. PHPStan's phar registers
// its own installation too, so there is more than one root to leave out. A root linked from outside
// its install location (a monorepo's path-repository package) is vendor only outside the first-party
// paths: such a package is installed by Composer yet analysed, while a first-party path holding the
// whole project (the %paths% default) never exempts a package installed into the vendor directory.
final class VendorPaths
{

	private FirstPartyPaths $firstPartyPaths;

	/** @var list<array{root: array{name: string}, versions: array<string, array{install_path?: string}>}>|null */
	private ?array $installations;

	/** @var array{FirstPartyPaths, FirstPartyPaths}|null */
	private ?array $roots = null;

	/**
	 * @param list<array{root: array{name: string}, versions: array<string, array{install_path?: string}>}>|null $installations
	 */
	public function __construct(FirstPartyPaths $firstPartyPaths, ?array $installations = null)
	{
		$this->firstPartyPaths = $firstPartyPaths;
		$this->installations = $installations;
	}

	public function contains(string $file): bool
	{
		[$installedRoots, $linkedRoots] = $this->roots();

		return $installedRoots->contains($file)
			|| ($linkedRoots->contains($file) && !$this->firstPartyPaths->contains($file));
	}

	/**
	 * @return array{FirstPartyPaths, FirstPartyPaths}
	 */
	private function roots(): array
	{
		if ($this->roots !== null) {
			return $this->roots;
		}

		$installed = [];
		$linked = [];
		foreach ($this->installations ?? InstalledVersions::getAllRawData() as $installation) {
			foreach ($installation['versions'] as $package => $version) {
				if ($package === $installation['root']['name'] || !isset($version['install_path'])) {
					continue;
				}

				$installPath = $version['install_path'];
				if (self::isLinked($installPath)) {
					$linked[] = $installPath;
				} else {
					$installed[] = $installPath;
				}
			}
		}

		return $this->roots = [new FirstPartyPaths($installed), new FirstPartyPaths($linked)];
	}

	private static function isLinked(string $installPath): bool
	{
		$real = realpath($installPath);
		$location = realpath(dirname($installPath));
		if ($real === false || $location === false) {
			return false;
		}

		return strncmp($real, $location . DIRECTORY_SEPARATOR, strlen($location) + 1) !== 0;
	}

}
