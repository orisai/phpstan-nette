<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class WrapperConfiguredRenderer
{

	public function createComponentWrappedForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('email');

		return $form;
	}

}
