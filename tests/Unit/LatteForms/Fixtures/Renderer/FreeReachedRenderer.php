<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of R1's global-function case: the callee is a project-declared FREE FUNCTION, so
// a parameter side scanning class methods alone never reads its body.
final class FreeReachedRenderer extends PairingControl
{

	public function createComponentFreeForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
