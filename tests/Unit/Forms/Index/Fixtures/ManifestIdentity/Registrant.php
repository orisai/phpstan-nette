<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\ManifestIdentity;

use Nette\Application\UI\Form;

class Registrant
{

	public function createComponentOrder(): Form
	{
		$form = new Form();
		$form->addText('name');
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function orderSucceeded(Form $form): void
	{
	}

}
