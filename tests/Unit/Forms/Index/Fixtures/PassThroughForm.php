<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class PassThroughForm
{

	public function createComponentMain(): Form
	{
		$form = new Form();
		$form->addText('field');
		$form->onSuccess[] = [$this, 'mainSucceeded'];

		return $form;
	}

	public function mainSucceeded(Form $form): void
	{
		$this->fillForm($form);
	}

	public function fillForm(Form $form): void
	{
	}

}
