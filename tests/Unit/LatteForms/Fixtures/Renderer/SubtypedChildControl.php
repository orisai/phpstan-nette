<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The class that actually holds the mutated component, one level below the type its builder
// declares - so the owner comparison has to be subtype-aware or the fact is lost here.
final class SubtypedChildControl extends SubtypedBaseControl
{

	public function createComponentSubtypedForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
