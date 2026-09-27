<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\MergeOrder;

use Nette\Application\UI\Form;

class SharedForm extends Form
{

}

class MergeOrderConflict
{
	use CreatesSharedForm;

	public function createComponentBeta(): SharedForm
	{
		$form = new SharedForm();
		$form->addCheckbox('x');
		$form->onSuccess[] = [$this, 'sharedSucceeded'];

		return $form;
	}

	public function sharedSucceeded(Form $form): void
	{
	}

}
