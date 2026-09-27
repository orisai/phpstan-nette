<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

/**
 * The value type must be identical across every source: $form->getValues(),
 * $form->getUntrustedValues(), $form->getUnsafeValues(), and the values
 * parameter of an on* callback — for both the object (ArrayHash) and array forms.
 */
final class MFormValuesEqualGetValuesAndCallback extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addCheckbox('agree');

		$form->onSuccess[] = function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{name: string, agree: bool}
		};

		$form->onSuccess[] = function (ApplicationForm $form, array $values): void {
			dumpType($values); // => array{name: string, agree: bool}
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{name: string, agree: bool}
		dumpType($this['form']->getUntrustedValues()); // => Nette\Utils\ArrayHash{name: string, agree: bool}
		dumpType($this['form']->getUnsafeValues()); // => Nette\Utils\ArrayHash{name: string, agree: bool}
		dumpType($this['form']->getValues(true)); // => array{name: string, agree: bool}
	}

}
