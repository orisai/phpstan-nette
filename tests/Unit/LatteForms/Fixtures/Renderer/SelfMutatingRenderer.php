<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class SelfMutatingRenderer
{

	public function createComponentLateForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

	public function handleLate(): void
	{
		$this['lateForm']->addHidden('late');
	}

}
