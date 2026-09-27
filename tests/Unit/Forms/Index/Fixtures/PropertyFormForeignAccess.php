<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class PropertyFormForeignAccess
{

	private PlainFactoryForm $form;

	public function __construct()
	{
		$this->form = new PlainFactoryForm();
		$this->form->addText('base');
	}

	public function copyInto(self $other): void
	{
		$other->form->addText('foreign');
	}

}
