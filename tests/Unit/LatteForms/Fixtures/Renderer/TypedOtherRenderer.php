<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class TypedOtherRenderer
{

	public function createComponentChoiceForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('years');
		$form->addText('name');
		$form->addSubmit('send');

		return $form;
	}

}
