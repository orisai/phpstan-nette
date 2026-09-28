<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer half of R3: the form is handed to $this->onReach(), which is a PROPERTY holding
// callables rather than a declared method. What runs is whatever a caller subscribed, so the
// hand-over can never be decided against a callee body.
final class EventReachedRenderer extends PairingControl
{

	public function createComponentEventForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
