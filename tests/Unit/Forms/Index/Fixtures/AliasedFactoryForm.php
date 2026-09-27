<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

final class AliasedFactoryForm
{

	private AliasedFormFactory $factory;

	protected function createComponentForm(): Form
	{
		$service = $this->factory;

		$form = $service->create();
		$form->addText('name');

		if ($this->approved()) {
			$form->addText('note');
		}

		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(Form $form): void
	{
	}

	private function approved(): bool
	{
		return true;
	}

}
