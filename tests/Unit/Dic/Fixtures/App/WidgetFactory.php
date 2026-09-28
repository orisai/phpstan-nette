<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

interface WidgetFactory
{

	public function create(string $name): Widget;

}
