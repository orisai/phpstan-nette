<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

final class RegistrarClassFixture extends Control
{

	protected function createComponentEditForm(): Form
	{
		$form = new Form();
		$scenario = $form->addContainer('scenario');
		$scenario->addText('name');

		return $form;
	}

	public function buildEditForm(): Form
	{
		$form = new Form();
		$scenario = $form->addContainer('scenario');
		$scenario->addText('name');

		return $form;
	}

}
