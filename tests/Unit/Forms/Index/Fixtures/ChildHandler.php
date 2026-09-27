<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class ChildHandler extends ParentCallee
{

	public function createComponentChild(): Form
	{
		$form = new Form();
		$form->addText('child');
		$form->onSuccess[] = [$this, 'childSucceeded'];

		return $form;
	}

	public function childSucceeded(Form $form): void
	{
		$this->inheritedFill($form);
	}

}
