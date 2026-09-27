<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

final class DoubleRebindForm extends DoubleRebindBase
{

	private Form $prebuilt;

	protected function createComponentForm(): Form
	{
		$form = parent::createComponentForm();
		$form = $this->prebuilt;
		$form->addText('local');
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(Form $form): void
	{
	}

	public function render(): void
	{
		$noForm = true;
	}

}
