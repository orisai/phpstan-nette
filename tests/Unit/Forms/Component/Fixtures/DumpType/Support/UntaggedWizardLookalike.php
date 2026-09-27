<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Form;

final class UntaggedWizardLookalike extends Wizard
{

	protected function createStep1(): Form
	{
		$form = $this->createForm();
		$form->addText('username');

		return $form;
	}

}
