<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MEventFormReceiverClosureChild extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addSubmit('sendBack');

		return $form;
	}

}

final class MEventFormReceiverClosureResolves extends Control
{

	private MEventFormReceiverClosureChild $child;

	protected function createComponentChild(): MEventFormReceiverClosureChild
	{
		$service = $this->child;
		$service['form']->onSuccess[] = function (ApplicationForm $form): void {
			dumpType($form['sendBack']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
		};

		return $service;
	}

}
