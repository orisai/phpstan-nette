<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit\Latte3;

use Latte\Engine;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;

// What nette/application's LatteExtension wires: since 3.3 TemplateFactory no longer adds the
// UI extension itself, so a Latte 3 engine handed to it must already carry one.
final class UiLatteFactory implements LatteFactory
{

	public function create(?Control $control = null): Engine
	{
		$engine = new Engine();
		$engine->addExtension(new UIExtension($control));

		return $engine;
	}

}
