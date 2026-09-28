<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Nette\Forms\Container;

final class PairingContainer extends Container
{

	public function __construct()
	{
		$this->addText('hidden');
	}

}
