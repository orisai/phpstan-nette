<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class PropertyFormVisibility
{

	private PlainFactoryForm $privateForm;

	protected PlainFactoryForm $protectedForm;

	public PlainFactoryForm $publicForm;

	public function __construct()
	{
		$this->privateForm = new PlainFactoryForm();
		$this->privateForm->addText('name');

		$this->protectedForm = new PlainFactoryForm();
		$this->protectedForm->addText('name');

		$this->publicForm = new PlainFactoryForm();
		$this->publicForm->addText('name');
	}

}
