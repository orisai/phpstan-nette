<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

final class InheritedParentForm extends InheritedParentBase
{

	protected function createComponentForm(): Form
	{
		$form = parent::createComponentForm();
		$form->addText('childField');
		$form->onSuccess[] = [$this, 'formSucceeded'];

		return $form;
	}

	public function formSucceeded(Form $form): void
	{
	}

}
