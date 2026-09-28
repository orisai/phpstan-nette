<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Nette\Forms\Container;

final class NotAFormRenderer
{

	public function createComponentSimpleForm(): Container
	{
		$container = new Container();
		$container->addText('inside');

		return $container;
	}

}
