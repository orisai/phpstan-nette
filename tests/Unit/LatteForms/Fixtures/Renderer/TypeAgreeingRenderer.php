<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// A second renderer that agrees with ClosedRenderer about what every referenced name IS, so a
// mismatch holds on both linked paths and the message names them both.
final class TypeAgreeingRenderer
{

	public function createComponentSimpleForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addText('email');
		$form->addSubmit('send');
		$address = $form->addContainer('address');
		$address->addText('street');

		return $form;
	}

}
