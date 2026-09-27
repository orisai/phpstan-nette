<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class AliasedResolvingFactoryForm
{

	private DirectFormFactory $factory;

	protected function createComponentForm(): FactoryBuiltForm
	{
		$service = $this->factory;

		$form = $service->create();
		$form->addText('local');
		$form->onSuccess[] = [$this, 'aliasedSucceeded'];

		return $form;
	}

	public function aliasedSucceeded(FactoryBuiltForm $form): void
	{
	}

}
