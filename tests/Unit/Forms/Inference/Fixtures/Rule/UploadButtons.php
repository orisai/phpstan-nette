<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class UploadButtons
{

	// G10-01: dynamic arg — setValue() on upload field 'a' has no effect. [orisai.nette.forms.writeNoEffect]
	public function g10_01(string $x): void
	{
		$form = new ApplicationForm();
		$form->addUpload('a');
		$form['a']->setValue($x);
	}

	// G10-02: setValue() on upload field 'a' has no effect. [orisai.nette.forms.writeNoEffect]
	public function g10_02(string $x): void
	{
		$form = new ApplicationForm();
		$form->addMultiUpload('a');
		$form['a']->setDefaultValue($x);
	}

	// G10-03: no error; submit contributes no slot (bag is Nette\Utils\ArrayHash{})
	public function g10_03(): void
	{
		$form = new ApplicationForm();
		$form->addSubmit('s');
		$form->getValues();
	}

	// G10-04: no error; button contributes no slot
	public function g10_04(): void
	{
		$form = new ApplicationForm();
		$form->addButton('b');
		$form->getValues();
	}

	// G10-05: no error; submit button is not a value control (no write rule applies)
	public function g10_05(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addSubmit('s');
		$form['s']->setValue('x');
	}

}
