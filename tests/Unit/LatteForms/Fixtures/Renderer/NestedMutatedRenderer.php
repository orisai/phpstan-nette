<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class NestedMutatedRenderer
{

	public function createComponentNestedForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addContainer('shipping')->addText('street');

		return $form;
	}

}
