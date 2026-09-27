<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Form;

/**
 * @form-wizard
 */
final class MappedStepWizard extends Wizard
{

	protected function createStep1(): Form
	{
		$form = $this->createForm();
		$form->setMappedType(MappedFormDto::class);
		$form->addText('name');
		$form->addInteger('age');

		return $form;
	}

}
