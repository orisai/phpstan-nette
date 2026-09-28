<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class ClosedRenderer
{

	public function createComponentSimpleForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');
		$form->addText('email');
		$form->addSubmit('send');
		$address = $form->addContainer('address');
		$address->addText('street');

		return $form;
	}

	public function createComponentButtonBarForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('top');
		$bar = $form->addContainer('bar');
		$bar->addSubmit('go');

		return $form;
	}

	public function createComponentDeepForm(): PairingForm
	{
		$form = new PairingForm();
		$outer = $form->addContainer('outer');
		$inner = $outer->addContainer('inner');
		$inner->addText('deep');

		return $form;
	}

}
