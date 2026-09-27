<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class FactoryPropForm
{

	private DirectFormFactory $factory;

	protected function createComponentForm(): FactoryBuiltForm
	{
		$form = $this->factory->create();
		$form->addText('local');
		$form->onSuccess[] = [$this, 'factorySucceeded'];

		return $form;
	}

	public function factorySucceeded(FactoryBuiltForm $form): void
	{
	}

}
