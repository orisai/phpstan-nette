<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Nette\Application\UI\Form;

final class LocatorFixture
{

	protected function createComponentSignInForm(): Form
	{
		$form = new Form();
		$form->addText('username');

		return $form;
	}

	public function noFactory(): void
	{
	}

}
