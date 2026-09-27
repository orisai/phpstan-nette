<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support;

use Nette\Application\UI\Control;
use Nette\Forms\Form;

final class FmChainBuilder extends Control
{

	private FmChainFactory $factory;

	public function buildForm(): Form
	{
		return $this->factory->assemble();
	}

}
