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
		$c->setHtmlAttribute('data-peer', $a)->setRequired();

		$form['a'];
	}

	public function valueAccess(): void
	{
		$form = new ApplicationForm();
		$a = $form->addText('a');
		$c = $form->addText('c');
		$c->setHtmlAttribute('data-peer', $a)->setRequired();

		$form->getValues()->a;
	}

}
