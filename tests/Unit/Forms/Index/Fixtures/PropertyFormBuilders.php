<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class PropertyFormBuilders
{

	private PlainFactoryForm $lateForm;

	private PlainFactoryForm $twiceForm;

	public function build(): void
	{
		$this->lateForm = new PlainFactoryForm();
		$this->lateForm->addText('late');
	}

	public function first(): void
	{
		$this->twiceForm = new PlainFactoryForm();
		$this->twiceForm->addText('a');
	}

	public function second(): void
	{
		$this->twiceForm = new PlainFactoryForm();
		$this->twiceForm->addText('b');
	}

}
