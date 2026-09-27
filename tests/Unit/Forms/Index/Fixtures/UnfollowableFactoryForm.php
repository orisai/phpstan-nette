<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class UnfollowableFactoryForm
{

	private UnfollowableFactory $factory;

	protected function createComponentForm(): FactoryBuiltForm
	{
		$form = $this->factory->create();
		$form->addText('local');
		$form->onSuccess[] = [$this, 'unfollowableSucceeded'];

		return $form;
	}

	public function unfollowableSucceeded(FactoryBuiltForm $form): void
	{
	}

}
