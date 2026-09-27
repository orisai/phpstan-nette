<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

trait RegistersInner
{

	public function createComponentInner(): Form
	{
		$form = new Form();
		$form->addText('inner');
		$form->onSuccess[] = [$this, 'innerSucceeded'];

		return $form;
	}

	public function innerSucceeded(Form $form): void
	{
	}

}
