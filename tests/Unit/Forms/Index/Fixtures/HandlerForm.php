<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class HandlerForm
{

	public function createComponentOrder(): Form
	{
		$form = new Form();
		$form->addText('name');
		$form->addInteger('qty');
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function orderSucceeded(Form $form): void
	{
	}

}
