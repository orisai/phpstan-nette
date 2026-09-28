<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

final class BarService
{

	public FooService $foo;

	public function __construct(FooService $foo)
	{
		$this->foo = $foo;
	}

}
