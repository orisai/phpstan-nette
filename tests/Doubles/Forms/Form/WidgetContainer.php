<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Form;

use Nette\Forms\Container;

final class WidgetContainer extends Container
{

	public function addWidget(string $name): WidgetControl
	{
		return $this[$name] = new WidgetControl();
	}

}
