<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of R2: the callee copies its PARAMETER into a local and registers through the
// copy, so a parameter side that tracks the parameter variable alone sees nothing.
final class CopyReachedRenderer extends PairingControl
{

	public function createComponentCopyForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
