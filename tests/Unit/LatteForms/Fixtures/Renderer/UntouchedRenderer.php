<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Declares a component of the very name SelfMutatingRenderer's is mutated under. The mutation names
// its owner, so this one stays closed - the gate is keyed on the (class, component) pair, not on the
// name alone.
final class UntouchedRenderer
{

	public function createComponentLateForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
