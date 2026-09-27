<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\VendorGate;

use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

abstract class BaseControl extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('note')->setRequired();
		$form->onSuccess[] = [$this, 'formSucceeded'];

		return $form;
	}

	abstract protected function formSucceeded(ApplicationForm $form): void;

}
