<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// A removal the walk cannot attribute to a name: the one build step that can make a component the
// shape DOES list mean something else at runtime, because what it takes away can be added back as
// anything. UnknownReason::UNKNOWN_REMOVAL is how the Forms analyser records it, and the identity
// gate declines on it - unlike a dynamic ADD, which can never rebind a name (addComponent throws on
// a duplicate).
final class LostFieldRenderer
{

	private string $gone;

	public function __construct(string $gone)
	{
		$this->gone = $gone;
	}

	// The same names ClosedRenderer's simpleForm carries, under the same removal: a second linked
	// renderer that resolves the form but can say nothing about what its components ARE.
	public function createComponentSimpleForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addText('email');
		$form->addSubmit('send');
		$address = $form->addContainer('address');
		$address->addText('street');
		$form->removeComponent($form[$this->gone]);

		return $form;
	}

	public function createComponentLostForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('email');
		$form->addContainer('address');
		$form->removeComponent($form[$this->gone]);

		return $form;
	}

}
