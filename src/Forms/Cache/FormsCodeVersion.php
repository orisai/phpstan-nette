<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function assert;
use function dirname;
use function is_dir;
use function ksort;
use function sha1;
use function sha1_file;
use function strlen;
use function substr;

final class FormsCodeVersion
{

	private static ?string $filesDigest = null;

	private static ?string $phpstanVersion = null;

	/** @var array<string, string> */
	private static array $versions = [];

	public static function get(string $defaultContainerClass): string
	{
		if (isset(self::$versions[$defaultContainerClass])) {
			return self::$versions[$defaultContainerClass];
		}

		$serialized = self::filesDigest()
			. 'container:' . $defaultContainerClass . '|'
			. 'phpstan:' . self::phpstanVersion() . '|';

		return self::$versions[$defaultContainerClass] = sha1($serialized);
	}

	/**
	 * Every source tree a persisted shape can have been derived by. It is not the Forms tree alone:
	 * component-model machinery the walk consumes lives outside it, and a cache keyed on the Forms
	 * digest would keep serving entries an edit to that machinery has invalidated. A tree named here
	 * must therefore be one this extension READS, and adding one costs a single full invalidation.
	 *
	 * @return array<string, string> label => absolute directory
	 */
	private static function roots(): array
	{
		return [
			'forms' => dirname(__DIR__),
			'attachment' => dirname(__DIR__, 2) . '/Component/Attachment',
		];
	}

	private static function filesDigest(): string
	{
		if (self::$filesDigest !== null) {
			return self::$filesDigest;
		}

		$parts = [];
		foreach (self::roots() as $label => $root) {
			if (!is_dir($root)) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			);

			foreach ($iterator as $info) {
				assert($info instanceof SplFileInfo);
				if (!$info->isFile() || substr($info->getFilename(), -4) !== '.php') {
					continue;
				}

				$parts[$label . '/' . substr($info->getPathname(), strlen($root) + 1)] = (string) sha1_file(
					$info->getPathname(),
				);
			}
		}

		ksort($parts);

		$serialized = '';
		foreach ($parts as $relativePath => $hash) {
			$serialized .= $relativePath . ':' . $hash . '|';
		}

		return self::$filesDigest = $serialized;
	}

	// Serialized Type layouts change across phpstan/phpstan releases, so a persisted entry
	// built by one installed version must not be served to another.
	private static function phpstanVersion(): string
	{
		if (self::$phpstanVersion !== null) {
			return self::$phpstanVersion;
		}

		return self::$phpstanVersion = InstalledVersions::getVersion('phpstan/phpstan') ?? '';
	}

}
