<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Replicates app/Component/BranchControl: a container built elsewhere and attached whole
// ($form->addComponent($this->factory->create(), 'phone')). The Forms analyser records the name as
// a container with an EMPTY shape and no unknown reason of its own, because nothing ever walked its
// interior - here the controls PairingContainer's own constructor adds.
final class OpaqueContainerRenderer
{

	public function createComponentOpaqueForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('top');
		$form->addComponent(new PairingContainer(), 'opaque');

		return $form;
	}

}
