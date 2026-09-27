<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

final class MEventCallbackValuesParamResolves extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addCheckbox('agree');

		$form->onSuccess[] = function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values->name); // => string
			dumpType($values->agree); // => bool
		};

		$form->onSuccess[] = function (ApplicationForm $form, array $values): void {
			dumpType($values['name']); // => string
			dumpType($values['agree']); // => bool
		};

		return $form;
	}

}
