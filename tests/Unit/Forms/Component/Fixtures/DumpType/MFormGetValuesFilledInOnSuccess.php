<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use function PHPStan\dumpType;

/**
 * getValues() called inside an onSuccess callback is the filled shape (the form is
 * valid there), so required fields narrow; a bare getValues() outside stays broad.
 */
final class MFormGetValuesFilledInOnSuccess extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name')->setRequired();
		$form->addInteger('age');

		$form->onSuccess[] = function (ApplicationForm $form): void {
			dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string, age: int|null}
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{name: string, age: int|null}
	}

}
