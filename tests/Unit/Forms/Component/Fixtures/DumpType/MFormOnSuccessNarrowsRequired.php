<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

/**
 * Inside onSuccess the form is valid, so required fields are narrowed to their
 * filled type (non-empty-string, non-null int). onError and a bare getValues()
 * keep the broad type. A non-required field is unchanged everywhere.
 */
final class MFormOnSuccessNarrowsRequired extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name')->setRequired();
		$form->addText('note');
		$form->addInteger('age')->setRequired();

		$form->onSuccess[] = function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{name: non-empty-string, note: string, age: int}
		};

		$form->onError[] = function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{name: string, note: string, age: int|null}
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{name: string, note: string, age: int|null}
	}

}
