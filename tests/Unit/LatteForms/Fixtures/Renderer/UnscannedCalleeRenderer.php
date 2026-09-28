<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The deliberate asymmetry's pin: this form is handed - array-wrapped, exactly as the corpus spells
// it - to a callee no fold ever sees declared. "No callee fact" keeps meaning "assumed clean", and
// flipping that would open every hand-over into vendor code.
final class UnscannedCalleeRenderer extends PairingControl
{

	public function createComponentUnscannedForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
