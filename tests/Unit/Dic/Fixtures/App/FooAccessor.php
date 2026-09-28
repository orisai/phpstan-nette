<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

interface FooAccessor
{

	public function get(): FooService;

}
