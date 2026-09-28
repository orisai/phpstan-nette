<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The negative control of the hand-over rule: IndirectMutator passes this form to a method that only
// READS it. A hand-over is not a mutation by itself, or half the corpus would lose its gate to
// setDefaults() helpers.
final class ReadOnlyReachedRenderer extends PairingControl
{

	public function createComponentReadOnlyForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
