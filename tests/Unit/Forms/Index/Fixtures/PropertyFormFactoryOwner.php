<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class PropertyFormFactoryOwner
{

	private DirectFormFactory $factory;

	private FactoryBuiltForm $form;

	public function __construct()
	{
		$this->form = $this->factory->create();
		$this->form->addText('local');
	}

}
