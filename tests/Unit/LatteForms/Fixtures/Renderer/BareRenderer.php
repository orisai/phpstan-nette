<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class BareRenderer
{

	public function createComponentOtherForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('other');

		return $form;
	}

}
