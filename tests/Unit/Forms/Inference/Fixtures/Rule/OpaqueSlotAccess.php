<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class OpaqueSlotAccess
{

	public function componentAccess(): void
	{
		$form = new ApplicationForm();
		$a = $form->addText('a');
		$c = $form->addText('c');
		$c->addConditionOn($a, $form::EQUAL, true)->setRequired();

		$form['a'];
	}

	public function valueAccess(): void
	{
		$form = new ApplicationForm();
		$a = $form->addText('a');
		$c = $form->addText('c');
		$c->addConditionOn($a, $form::EQUAL, true)->setRequired();

		$form->getValues()->a;
	}

}
