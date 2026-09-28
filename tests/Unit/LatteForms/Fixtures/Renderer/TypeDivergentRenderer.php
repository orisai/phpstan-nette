<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The shared-partial shape for the type checks: the same component names, every one of them the
// OTHER kind. Both ways a mismatch can be silenced meet here - `address` is a control this template's
// {input} addresses correctly, while `send` is a container, which disagrees with the labelless
// verdict ClosedRenderer's button earns rather than agreeing with it.
final class TypeDivergentRenderer
{

	public function createComponentSimpleForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addText('address');
		$form->addContainer('email');
		$form->addContainer('send');

		return $form;
	}

}
