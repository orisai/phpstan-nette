<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of the external-mutation class: a builder that is complete on its own terms and
// carries no unknown reason, whose form nevertheless gains a component elsewhere. Nothing here says
// so - OutsideMutator and ParentPresenterReplica are where the evidence lives.
final class ExternallyMutatedRenderer
{

	public function createComponentEmployerForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addText('country');

		return $form;
	}

}
