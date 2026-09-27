<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Utils\ArrayHash;
use stdClass;
use function PHPStan\dumpType;

/**
 * Form values behave equally across every consumption form: array, ArrayHash
 * (universal object crate + ArrayAccess) and stdClass. Property and offset access
 * resolve to the exact field type in all of them — closing the loophole where
 * ArrayHash offset access used to degrade to mixed.
 */
final class MFormValuesArrayHashOffsetEquals extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addCheckbox('agree');

		return $form;
	}

	public function arrayHash(): void
	{
		$values = $this['form']->getValues();
		dumpType($values); // => Nette\Utils\ArrayHash{name: string, agree: bool}
		dumpType($values->name); // => string
		dumpType($values['name']); // => string
		dumpType($values->agree); // => bool
		dumpType($values['agree']); // => bool
	}

	public function stdClassCrate(): void
	{
		$values = $this['form']->getValues(stdClass::class);
		dumpType($values); // => stdClass{name: string, agree: bool}
		dumpType($values->name); // => string
		dumpType($values['name']); // => string
	}

	public function arrayForm(): void
	{
		$values = $this['form']->getValues(true);
		dumpType($values); // => array{name: string, agree: bool}
		dumpType($values['name']); // => string
		dumpType($values['agree']); // => bool
	}

	public function stillAssignableToArrayHash(): void
	{
		$this->consumeArrayHash($this['form']->getValues());
	}

	private function consumeArrayHash(ArrayHash $values): void
	{
	}

}
