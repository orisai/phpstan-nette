<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\RealAnalyse;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;

final class UnknownNameOnClosedReplicatorOwnShape
{

	public function go(): void
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$holder = $form['rows'];
		$holder['nope'];
	}

}
