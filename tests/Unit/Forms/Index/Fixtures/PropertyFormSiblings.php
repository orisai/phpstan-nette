<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class PropertyFormSiblings
{

	private PlainFactoryForm $mutatedForm;

	private PlainFactoryForm $readForm;

	public function __construct()
	{
		$this->mutatedForm = new PlainFactoryForm();
		$this->mutatedForm->addText('base');

		$this->readForm = new PlainFactoryForm();
		$this->readForm->addText('base');
	}

	public function extend(): void
	{
		$this->mutatedForm->addText('later');
	}

	public function read(): void
	{
		$this->readForm->getValues();
	}

}
