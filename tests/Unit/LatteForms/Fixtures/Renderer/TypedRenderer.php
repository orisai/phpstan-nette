<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class TypedRenderer
{

	private string $dynamic;

	public function __construct(string $dynamic)
	{
		$this->dynamic = $dynamic;
	}

	public function createComponentChoiceForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addCheckboxList('years', 'Years', [2024 => '2024', 2025 => '2025']);
		$form->addText('name');
		$form->addSubmit('send');

		return $form;
	}

	public function createComponentHollowForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addContainer('empty');

		return $form;
	}

	public function createComponentProtectedForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('a');
		$form->addSubmit($this->dynamic);

		return $form;
	}

	public function createComponentButtonOnlyForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addSubmit('send');

		return $form;
	}

}
