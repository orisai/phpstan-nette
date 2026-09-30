<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Support;

use PHPStan\Analyser\Scope;
use ReflectionClass;
use function class_exists;
use function dirname;
use function is_file;
use function version_compare;
use const PHP_VERSION;

final class PhpstanRuntimeStubs
{

	private const STUBS = [
		'ReflectionUnionType' => 'ReflectionUnionType.php',
		'ReflectionIntersectionType' => 'ReflectionIntersectionType.php',
		'ReflectionAttribute' => 'ReflectionAttribute.php',
		'Attribute' => 'Attribute85.php',
	];

	private static bool $loaded = false;

	private function __construct()
	{
	}

	public static function ensureLoaded(): void
	{
		if (self::$loaded || version_compare(PHP_VERSION, '8.0.0', '>=')) {
			return;
		}

		self::$loaded = true;
		$scopeFile = (new ReflectionClass(Scope::class))->getFileName();
		if ($scopeFile === false) {
			return;
		}

		$directory = dirname($scopeFile, 3) . '/stubs/runtime/';
		foreach (self::STUBS as $name => $file) {
			if (class_exists($name, false) || !is_file($directory . $file)) {
				continue;
			}

			require_once $directory . $file;
		}
	}

}
