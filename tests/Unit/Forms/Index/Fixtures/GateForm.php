<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class GateForm
{

	public function createComponentG1(): Form
	{
		$form = new Form();
		$form->addText('g1');
		$form->onSuccess[] = [$this, 'handlerG1'];

		return $form;
	}

	public function handlerG1(Form $form): void
	{
		$this->missingCallee($form);
	}

	public function createComponentG2(): Form
	{
		$form = new Form();
		$form->addText('g2');
		$form->onSuccess[] = [$this, 'handlerG2'];

		return $form;
	}

	public function handlerG2(Form $form): void
	{
		$this->consumeString($form);
	}

	public function consumeString(string $label): void
	{
	}

	public function createComponentG3(): Form
	{
		$form = new Form();
		$form->addText('g3');
		$form->onSuccess[] = [$this, 'nullableHandler'];

		return $form;
	}

	public function nullableHandler(?Form $form): void
	{
		$this->fillNullable($form);
	}

	public function fillNullable(Form $form): void
	{
	}

}
