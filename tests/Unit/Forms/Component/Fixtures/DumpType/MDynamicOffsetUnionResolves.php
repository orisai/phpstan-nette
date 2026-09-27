<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use function PHPStan\dumpType;

final class MDynamicOffsetUnionResolves extends BaseFormControl
{

	/** @return array<int, string> */
	private function names(): array
	{
		return ['a', 'b'];
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addCheckbox('b');

		return $form;
	}

	public function render(): void
	{
		foreach ($this->names() as $name) {
			dumpType($this['form'][$name]); // => Nette\Forms\Controls\Checkbox|Nette\Forms\Controls\TextInput
		}
	}

}
