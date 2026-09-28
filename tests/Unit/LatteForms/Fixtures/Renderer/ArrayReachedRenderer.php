<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of the ARRAY-WRAPPED hand-over: IndirectMutator::actionArrayWrapped passes this
// form inside a literal array, and the callee registers on the array's elements.
final class ArrayReachedRenderer extends PairingControl
{

	public function createComponentArrayForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
