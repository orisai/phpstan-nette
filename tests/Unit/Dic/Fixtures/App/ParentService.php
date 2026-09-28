<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

abstract class ParentService
{

	public FooService $foo;

	public function __construct(FooService $foo)
	{
		$this->foo = $foo;
	}

	public function configureParent(): void
	{
	}

}
