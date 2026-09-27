<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use function PHPStan\dumpType;

/**
 * $form['field']->getValue() must equal the matching member of $form->getValues()
 * for every control kind — including controls that do not override
 * BaseControl::getValue() (Checkbox), where a naive dynamic-return extension would
 * be widened back to mixed by the @return mixed declaration.
 */
final class MControlGetValueEqualsMember extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addCheckbox('agree');
		$form->addInteger('count');
		$form->addSelect('country', null, [1 => 'A', 2 => 'B']);

		$bag = $form->addContainer('bag');
		$bag->addText('inner');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['name']->getValue()); // => string
		dumpType($this['form']['agree']->getValue()); // => bool
		dumpType($this['form']['count']->getValue()); // => int|null
		dumpType($this['form']['country']->getValue()); // => 1|2|null
		dumpType($this['form']['bag']['inner']->getValue()); // => string
	}

}
