<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class TwoSiteForm
{

	public function createComponentA(): Form
	{
		$form = new Form();
		$form->addText('a');
		$form->onSuccess[] = [$this, 'sharedSucceeded'];

		return $form;
	}

	public function createComponentB(): Form
	{
		$form = new Form();
		$form->addText('b');
		$form->onSuccess[] = [$this, 'sharedSucceeded'];

		return $form;
	}

	public function sharedSucceeded(Form $form): void
	{
	}

}
