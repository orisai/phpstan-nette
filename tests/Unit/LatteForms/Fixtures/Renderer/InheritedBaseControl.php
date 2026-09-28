<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The class a shared template is linked to: it DECLARES the component, while the only owner a
// mutation site can name is a SUBCLASS of it - the mirror of SubtypedBaseControl.
abstract class InheritedBaseControl extends PairingControl
{

	public function createComponentInheritedForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
