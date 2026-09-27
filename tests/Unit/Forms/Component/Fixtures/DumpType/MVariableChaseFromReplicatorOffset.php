<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MVariableChaseFromReplicatorOffset extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('username');
		});

		$row = $form['rep'][0];
		dumpType($row['username']); // => Nette\Forms\Controls\TextInput

		return $form;
	}

}
