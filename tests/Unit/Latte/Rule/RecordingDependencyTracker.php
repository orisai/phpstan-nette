<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use PHPStan\Analyser\DependencyTracker;

final class RecordingDependencyTracker implements DependencyTracker
{

	/** @var list<string> */
	public array $files = [];

	public function trackValueDependency(string $extensionClass, string $key): void
	{
	}

	public function trackFileDependency(string $file): void
	{
		$this->files[] = $file;
	}

	public function trackDirectoryDependency(string $directory, string $pattern = '*'): void
	{
	}

	public function trackClassDependency(string $className): void
	{
	}

}
