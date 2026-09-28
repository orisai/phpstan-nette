<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;

abstract class ControlArgumentSelfChainBase extends Control
{

	protected function createTemplate(): Template
	{
		return parent::createTemplate();
	}

}
