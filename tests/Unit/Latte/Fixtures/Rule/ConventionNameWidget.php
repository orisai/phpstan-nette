<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

// Replica of the convention-locator receiver shape: setFile() writes the $this->file the locator
// later turns into <dir>/lcfirst($this->file).latte.
class ConventionNameWidget
{

	/** @var string */
	protected $file;

	public function setFile(string $name): self
	{
		$this->file = $name;

		return $this;
	}

}
