<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

final class SetupService
{

	public ?FooService $dependency = null;

	public function setDependency(FooService $foo): void
	{
		$this->dependency = $foo;
	}

}
