<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Form;

/**
 * @form-wizard
 */
final class PartlyOpaqueWizard extends Wizard
{

	protected function createStep1(): Form
	{
		$form = $this->createForm();
		$form->addText('username');

		return $form;
	}

	protected function createStep2(): OpaqueStepForm
	{
		$form = new OpaqueStepForm();
		$form->addMystery('blob');

		return $form;
	}

}
