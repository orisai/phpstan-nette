<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PHPStan\Analyser\DependencyTracker;

final class RecordingDependencyTracker implements DependencyTracker
{

	/** @var list<string> */
	public array $files = [];

	/** @var list<array{string, string}> extension class, key */
	public array $values = [];

	public function trackValueDependency(string $extensionClass, string $key): void
	{
		$this->values[] = [$extensionClass, $key];
	}

	public function trackFileDependency(string $file): void
	{
		$this->files[] = $file;
	}

	public function trackDirectoryDependency(string $directory, string $pattern = '*'): void
	{
		// no test asserts a directory dependency
	}

	public function trackClassDependency(string $className): void
	{
		// no test asserts a class dependency
	}

}
