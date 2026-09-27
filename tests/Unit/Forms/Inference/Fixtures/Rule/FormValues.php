<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;
use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class FormValues
{

	public function dump(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$address = $form->addContainer('address');
		$address->addText('city');
		$address->addText('zip');
		dumpFormValues($form);
	}

	public function matches(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		assertFormValues($form, 'Nette\Utils\ArrayHash{name: string}');
	}

	public function mismatches(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		assertFormValues($form, 'Nette\Utils\ArrayHash{}');
	}

	public function button(): void
	{
		$form = new ApplicationForm();
		$form->addButton('act', 'Go');
		assertFormValues($form, 'Nette\Utils\ArrayHash{act: string|null}');
	}

}
