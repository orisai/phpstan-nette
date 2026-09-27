<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class AllKinds
{

	public function createComponentOrder()
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function passesFormParam(Form $form): void
	{
		$this->fillForm($form);
	}

	public function buildsFormDirectly(): Form
	{
		return new Form();
	}

}
