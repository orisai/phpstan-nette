<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

final class MEventCallbackFirstParamValuesResolves extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');

		$form->onSuccess[] = function (ArrayHash $values): void {
			dumpType($values->name); // => string
		};

		$form->onSuccess[] = function (array $values): void {
			dumpType($values['name']); // => string
		};

		return $form;
	}

}
