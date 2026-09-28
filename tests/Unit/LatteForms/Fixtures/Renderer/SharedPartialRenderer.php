<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The legitimate shared-partial pattern: one template rendered by several classes, where a name
// only one of them declares is correct code rather than a typo.
final class SharedPartialRenderer
{

	public function createComponentSimpleForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addText('nope');

		return $form;
	}

}
