<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use FilesystemIterator;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function array_unique;
use function array_values;
use function assert;
use function dirname;
use function implode;
use function is_dir;
use function ksort;
use function realpath;
use function sha1;
use function sha1_file;
use function sort;
use function strlen;
use function substr;
use const SORT_STRING;

final class FormsCodeVersion
{

	private static ?string $filesDigest = null;

	private static ?string $phpstanVersion = null;

	/** @var array<string, string> */
	private static array $versions = [];

	/**
	 * @param list<string> $analysedPaths
	 */
	public static function get(string $defaultContainerClass, array $analysedPaths = []): string
	{
		$pathsDigest = self::pathsDigest($analysedPaths);
		$memoKey = $defaultContainerClass . '|' . $pathsDigest;
		if (isset(self::$versions[$memoKey])) {
			return self::$versions[$memoKey];
		}

		$serialized = self::filesDigest()
			. 'container:' . $defaultContainerClass . '|'
			. 'paths:' . $pathsDigest . '|'
			. 'phpstan:' . self::phpstanVersion() . '|';

		return self::$versions[$memoKey] = sha1($serialized);
	}

	// The declared paths decide which files count as project code: ContainerModel's vendor-method
	// gates, the registrar convention check and the index containment gate all branch on
	// AnalysedPaths::isAnalysed(), and the shapes derived under one answer persist under content-only
	// keys. Normalised so a reordered, duplicated or symlinked declaration of the same universe
	// shares one store.

	/**
	 * @param list<string> $analysedPaths
	 */
	public static function pathsDigest(array $analysedPaths): string
	{
		$bases = [];
		foreach ($analysedPaths as $path) {
			$real = realpath($path);
			$bases[] = $real === false ? $path : $real;
		}

		$bases = array_values(array_unique($bases));
		sort($bases, SORT_STRING);

		return sha1(implode("\0", $bases));
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

		return self::$phpstanVersion = ProjectInstalledVersions::get()->getVersion('phpstan/phpstan') ?? '';
	}

}
