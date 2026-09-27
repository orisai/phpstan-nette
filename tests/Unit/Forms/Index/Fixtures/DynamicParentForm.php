<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

final class DynamicParentForm extends InheritedParentBase
{

	protected function createComponentForm(): Form
	{
		$method = 'createComponentForm';
		$form = parent::$method();
		$form->addText('local');
		$form->onSuccess[] = [$this, 'dynamicSucceeded'];

		return $form;
	}

	public function dynamicSucceeded(Form $form): void
	{
	}

}
