<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use function PHPStan\dumpType;

/**
 * After an isValid()/isSuccess() guard the form is validated, so getValues() in that
 * branch projects the filled shape; outside the guard it stays broad.
 */
final class MFormGetValuesFilledAfterIsValid extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name')->setRequired();
		$form->addInteger('age');

		return $form;
	}

	public function go(): void
	{
		if ($this['form']->isValid()) {
			dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string, age: int|null}
		}

		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{name: string, age: int|null}
	}

}
