<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Form;

/**
 * @form-wizard
 */
final class MyWizard extends Wizard
{

	protected function createStep1(): Form
	{
		$form = $this->createForm();
		$form->addText('username')->setRequired();

		return $form;
	}

	protected function createStep2(): Form
	{
		$form = $this->createForm();
		$form->addText('email');

		return $form;
	}

}
