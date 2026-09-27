<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class PropertyFormEscape
{

	private PlainFactoryForm $form;

	public function __construct()
	{
		$this->form = new PlainFactoryForm();
		$this->form->addText('base');
	}

	public function getForm(): PlainFactoryForm
	{
		return $this->form;
	}

}
