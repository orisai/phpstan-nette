<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// A builder complete on its own terms whose form is reached from outside (TypeOutsideMutator). An
// outside reach may removeComponent() as easily as add to it, so nothing this shape says about what
// a name IS survives it.
final class TypeMutatedRenderer
{

	public function createComponentTypedForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('email');
		$form->addContainer('address');

		return $form;
	}

}
