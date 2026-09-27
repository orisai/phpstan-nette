<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\RealAnalyse;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class UnknownKeyOnClosedValuesShape
{

	public function go(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$values = $form->getValues();
		$values['nope'];
	}

}
