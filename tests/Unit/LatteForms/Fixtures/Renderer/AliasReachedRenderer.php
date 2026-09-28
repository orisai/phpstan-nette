<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of the two-hop alias spelling: IndirectMutator::actionAlias copies the access
// into one local and that local into another before mutating it.
final class AliasReachedRenderer extends PairingControl
{

	public function createComponentAliasForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
