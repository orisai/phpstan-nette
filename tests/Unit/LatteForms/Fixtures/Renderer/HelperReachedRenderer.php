<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of the hand-over spelling: IndirectMutator::actionHelper passes this form to a
// method of its own, which registers on it under a name no builder mentions.
final class HelperReachedRenderer extends PairingControl
{

	public function createComponentHelperForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
