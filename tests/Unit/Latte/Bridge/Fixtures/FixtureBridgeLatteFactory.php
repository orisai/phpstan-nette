<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures;

use Latte\Engine;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\LatteFactory;

final class FixtureBridgeLatteFactory implements LatteFactory
{

	public function create(?Control $control = null): Engine
	{
		return new Engine();
	}

}
