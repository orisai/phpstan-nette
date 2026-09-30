<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Tools\Corpus;

final class HarvestSource
{

	public string $package;

	public string $version;

	public string $checkoutDirectory;

	/** @var list<string> */
	public array $testDirectories;

	/**
	 * @param list<string> $testDirectories
	 */
	public function __construct(string $package, string $version, string $checkoutDirectory, array $testDirectories)
	{
		$this->package = $package;
		$this->version = $version;
		$this->checkoutDirectory = $checkoutDirectory;
		$this->testDirectories = $testDirectories;
	}

}
