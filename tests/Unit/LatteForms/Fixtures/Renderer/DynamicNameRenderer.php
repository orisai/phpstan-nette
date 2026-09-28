<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Replicates app/Factory/RegistrationFormFactory:70 ($recommendedUsersBtn->addButton($recommendedId)):
// a component attached under a computed name. It contributes no VALUE, so the value axis of
// UnknownInfo says nothing about it - only the name axis records that a name was lost.
final class DynamicNameRenderer
{

	private string $dynamic;

	public function __construct(string $dynamic)
	{
		$this->dynamic = $dynamic;
	}

	public function createComponentDynamicForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('known');
		$form->addSubmit($this->dynamic);

		return $form;
	}

}
