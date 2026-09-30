<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Support;

use Composer\InstalledVersions;
use function array_keys;
use function is_string;
use function realpath;
use function strlen;
use function strncmp;

// Composer\InstalledVersions answers from the FIRST registered install that knows a package, and
// PHPStan's phar registers its own bundled dependencies (nette/di, nette/utils, ...) before the
// analysed project's. The install this extension belongs to is the one that lists it.
final class ProjectInstalledVersions
{

	public const PACKAGE = 'orisai/phpstan-nette';

	/** @var array<string, array<string, mixed>> */
	private array $versions;

	private ?string $rootPackage;

	/** @var array<string, string>|null */
	private ?array $packageRoots = null;

	private static ?self $instance = null;

	/**
	 * @param array<string, array<string, mixed>> $versions
	 */
	private function __construct(array $versions, ?string $rootPackage)
	{
		$this->versions = $versions;
		$this->rootPackage = $rootPackage;
	}

	public static function get(): self
	{
		return self::$instance ??= self::fromRawData(InstalledVersions::getAllRawData());
	}

	/**
	 * @param list<array{root: array<string, mixed>, versions: array<string, array<string, mixed>>}> $rawData
	 */
	public static function fromRawData(array $rawData): self
	{
		foreach ($rawData as $install) {
			if (isset($install['versions'][self::PACKAGE])) {
				$rootPackage = $install['root']['name'] ?? null;

				return new self($install['versions'], is_string($rootPackage) ? $rootPackage : null);
			}
		}

		return new self([], null);
	}

	public function getVersion(string $package): ?string
	{
		$version = $this->versions[$package]['version'] ?? null;

		return is_string($version) ? $version : null;
	}

	public function getPrettyVersion(string $package): ?string
	{
		$version = $this->versions[$package]['pretty_version'] ?? null;

		return is_string($version) ? $version : null;
	}

	public function getInstallPath(string $package): ?string
	{
		$path = $this->versions[$package]['install_path'] ?? null;

		return is_string($path) ? $path : null;
	}

	public function getReference(string $package): ?string
	{
		$reference = $this->versions[$package]['reference'] ?? null;

		return is_string($reference) ? $reference : null;
	}

	// The installed package whose install root is or holds the path (the deepest one), never the
	// root package: a file of the project itself is first-party.
	public function packageContaining(string $file): ?string
	{
		$real = realpath($file);
		if ($real === false) {
			return null;
		}

		$found = null;
		$foundLength = 0;
		foreach ($this->packageRoots() as $package => $root) {
			$length = strlen($root);
			if ($length > $foundLength && ($real === $root || strncmp($real, $root . '/', $length + 1) === 0)) {
				$found = $package;
				$foundLength = $length;
			}
		}

		return $found;
	}

	/**
	 * @return array<string, string>
	 */
	private function packageRoots(): array
	{
		if ($this->packageRoots !== null) {
			return $this->packageRoots;
		}

		$roots = [];
		foreach (array_keys($this->versions) as $package) {
			$path = $this->getInstallPath($package);
			if ($package === $this->rootPackage || $path === null) {
				continue;
			}

			$real = realpath($path);
			if ($real !== false) {
				$roots[$package] = $real;
			}
		}

		return $this->packageRoots = $roots;
	}

}
