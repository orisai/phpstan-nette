<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class MEventParamCallOnLocalFormTerminates extends Control
{

	protected function createComponentCheckForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('spotNumber');
		$form->addSubmit('send');

		$form->onValidate[] = function (Form $form): void {
			dumpType($form['spotNumber']); // => Nette\Forms\Controls\TextInput
			$form->addError('taken');
		};

		return $form;
	}

}
