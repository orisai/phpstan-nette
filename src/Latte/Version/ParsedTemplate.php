<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

final class ParsedTemplate
{

	private string $relativePath;

	public function __construct(string $relativePath)
	{
		$this->relativePath = $relativePath;
	}

	public function getRelativePath(): string
	{
		return $this->relativePath;
	}

}
