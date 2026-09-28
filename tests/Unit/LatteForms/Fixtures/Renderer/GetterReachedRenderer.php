<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of the getter spelling: the control hands its form out through a method, so the
// mutation site names the control but never the form. IndirectMutator::actionGetter is the evidence.
final class GetterReachedRenderer extends PairingControl
{

	public function createComponentGetterForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

	public function getForm(): PairingForm
	{
		return $this['getterForm'];
	}

}
