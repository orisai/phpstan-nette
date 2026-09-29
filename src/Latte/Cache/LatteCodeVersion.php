<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use FilesystemIterator;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function assert;
use function dirname;
use function ksort;
use function sha1;
use function sha1_file;
use function strlen;
use function substr;

final class LatteCodeVersion
{

	// Every package whose sources shape what lands in LatteAnalysisCache. A producer left out here
	// would go on serving entries its own bug fixes never invalidate, so the bridge packages that
	// write into the cache must all be listed.
	public const SALTED_PACKAGES = ['Latte', 'LatteForms'];

	private static ?string $version = null;

	public static function get(): string
	{
		if (self::$version !== null) {
			return self::$version;
		}

		return self::$version = self::combine(
			self::filesDigest(),
			ProjectInstalledVersions::get()->getVersion('phpstan/phpstan') ?? '',
			ProjectInstalledVersions::get()->getVersion('latte/latte') ?? '',
			ProjectInstalledVersions::get()->getVersion('nette/application') ?? '',
			ProjectInstalledVersions::get()->getVersion('nette/forms') ?? '',
		);
	}

	public static function combine(
		string $filesDigest,
		string $phpstanVersion,
		string $latteVersion,
		string $applicationVersion,
		string $formsVersion
	): string
	{
		$serialized = $filesDigest
			. 'phpstan:' . $phpstanVersion . '|'
			. 'latte:' . $latteVersion . '|'
			. 'application:' . $applicationVersion . '|'
			. 'forms:' . $formsVersion . '|';

		return sha1($serialized);
	}

	public static function filesDigest(): string
	{
		$parts = [];

		foreach (self::SALTED_PACKAGES as $package) {
			$root = dirname(__DIR__, 2) . '/' . $package;
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			);

			foreach ($iterator as $info) {
				assert($info instanceof SplFileInfo);
				if (!$info->isFile() || substr($info->getFilename(), -4) !== '.php') {
					continue;
				}

				$key = $package . '/' . substr($info->getPathname(), strlen($root) + 1);
				$parts[$key] = (string) sha1_file($info->getPathname());
			}
		}

		ksort($parts);

		$serialized = '';
		foreach ($parts as $relativePath => $hash) {
			$serialized .= $relativePath . ':' . $hash . '|';
		}

		return $serialized;
	}

}
