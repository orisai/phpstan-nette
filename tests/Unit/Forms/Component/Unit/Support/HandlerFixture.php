<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

final class HandlerFixture extends Control
{

	protected function createComponentOrderForm(): Form
	{
		$form = new Form();
		$form->addText('note');
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function orderSucceeded(Form $form): void
	{
	}

	public function unrelated(Form $form): void
	{
	}

}
