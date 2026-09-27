<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class VarArmFactoryForm
{

	private DirectFormFactory $factory;

	protected function createComponentForm(): PlainFactoryForm
	{
		$form = $this->factory->build();
		$form->addText('local');
		$form->onSuccess[] = [$this, 'varArmSucceeded'];

		return $form;
	}

	public function varArmSucceeded(PlainFactoryForm $form): void
	{
	}

}
