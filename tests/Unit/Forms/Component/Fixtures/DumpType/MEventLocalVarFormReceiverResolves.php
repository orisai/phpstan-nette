<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MEventLocalVarFormReceiverChild extends BaseFormControl
{

	protected function createComponentReservationsForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addSubmit('sendBack');

		return $form;
	}

}

interface MEventLocalVarFormReceiverFactory
{

	public function create(): MEventLocalVarFormReceiverChild;

}

final class MEventLocalVarFormReceiverResolves extends Control
{

	private MEventLocalVarFormReceiverFactory $factory;

	protected function createComponentChild(): MEventLocalVarFormReceiverChild
	{
		$service = $this->factory->create();
		$service['reservationsForm']->onSuccess[] = function (ApplicationForm $form): void {
			dumpType($form['sendBack']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
		};

		return $service;
	}

}
