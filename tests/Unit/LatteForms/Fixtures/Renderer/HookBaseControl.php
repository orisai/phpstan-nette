<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

abstract class HookBaseControl extends PairingControl
{

	public function createComponentHookForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');
		$this->buildHookFields($form);

		return $form;
	}

	abstract protected function buildHookFields(PairingForm $form): void;

}
