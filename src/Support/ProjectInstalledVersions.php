<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Support;

use Composer\InstalledVersions;
use function is_string;

// Composer\InstalledVersions answers from the FIRST registered install that knows a package, and
// PHPStan's phar registers its own bundled dependencies (nette/di, nette/utils, ...) before the
// analysed project's. The install this extension belongs to is the one that lists it.
final class ProjectInstalledVersions
{

	public const PACKAGE = 'orisai/phpstan-nette';

	/** @var array<string, array<string, mixed>> */
	private array $versions;

	private static ?self $instance = null;

	/**
	 * @param array<string, array<string, mixed>> $versions
	 */
	private function __construct(array $versions)
	{
		$this->versions = $versions;
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
				return new self($install['versions']);
			}
		}

		return new self([]);
	}

	public function getVersion(string $package): ?string
	{
		$version = $this->versions[$package]['version'] ?? null;

		return is_string($version) ? $version : null;
	}

	public function getInstallPath(string $package): ?string
	{
		$path = $this->versions[$package]['install_path'] ?? null;

		return is_string($path) ? $path : null;
	}

}
