<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms;

use Tests\OriPhpstan\Nette\Unit\Forms\Support\UnannotatedRegistrarContainer;

final class UnannotatedRegistrarCalls
{

	public function build(): void
	{
		$form = new UnannotatedRegistrarContainer();
		$form->addPlain('plain');
		$form->addLabelled('Label', 'labelled');
		$form->addDeclaredLabelled('Label', 'declared');
		$form->addNothing('nothing');
	}

}
