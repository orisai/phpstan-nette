<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use Composer\InstalledVersions;
use OriPhpstan\Nette\Latte\Includes\FirstPartyPaths;

// Install roots of every Composer package except each installation's own root package - where a
// class is located, not how the vendor directory happens to be named. PHPStan's phar registers
// its own installation too, so there is more than one root to leave out. A first-party file is
// never vendor: a monorepo's path-repository package is installed by Composer yet analysed.
final class VendorPaths
{

	private FirstPartyPaths $firstPartyPaths;

	private ?FirstPartyPaths $installRoots = null;

	public function __construct(FirstPartyPaths $firstPartyPaths)
	{
		$this->firstPartyPaths = $firstPartyPaths;
	}

	public function contains(string $file): bool
	{
		return !$this->firstPartyPaths->contains($file) && $this->installRoots()->contains($file);
	}

	private function installRoots(): FirstPartyPaths
	{
		if ($this->installRoots !== null) {
			return $this->installRoots;
		}

		$paths = [];
		foreach (InstalledVersions::getAllRawData() as $installation) {
			foreach ($installation['versions'] as $package => $version) {
				if ($package !== $installation['root']['name'] && isset($version['install_path'])) {
					$paths[] = $version['install_path'];
				}
			}
		}

		return $this->installRoots = new FirstPartyPaths($paths);
	}

}
