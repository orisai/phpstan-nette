<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

final class ClassComponentArgForm extends Control
{

	protected function createComponentOrder(): Form
	{
		$form = new Form();
		$form->addText('field');

		return $form;
	}

	public function trigger(): void
	{
		$this->outerHelper($this['order']);
	}

	private function outerHelper(Form $form): void
	{
		$this->innerHelper($form);
	}

	private function innerHelper(Form $form): void
	{
	}

}
