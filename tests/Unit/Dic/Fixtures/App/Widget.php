<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

final class Widget
{

	public string $name;

	public function __construct(string $name)
	{
		$this->name = $name;
	}

}
