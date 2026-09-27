<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class MultiReturnForm
{

	public function createComponentEdit(bool $extra): Form
	{
		$form = new Form();
		$form->addText('name');
		$form->onSuccess[] = [$this, 'editSucceeded'];

		if ($extra) {
			$form->addText('note');

			return $form;
		}

		return $form;
	}

	public function editSucceeded(Form $form): void
	{
	}

}
