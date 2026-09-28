<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Handed over twice: IndirectMutator::actionChain passes this form to a method that passes it on
// again before anything registers on it, so only the closure over hand-overs reaches the mutation.
final class ChainReachedRenderer extends PairingControl
{

	public function createComponentChainForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
