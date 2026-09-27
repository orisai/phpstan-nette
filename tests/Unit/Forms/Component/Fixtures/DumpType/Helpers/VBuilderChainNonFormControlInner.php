<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Helpers;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;

final class VBuilderChainNonFormControlInner extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addHidden('id');

		return $form;
	}

}

interface VBuilderChainNonFormControlFactory
{

	public function create(): VBuilderChainNonFormControlInner;

}
