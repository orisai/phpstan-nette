<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function assert;

final class FactoryMutatedForm
{

	private SectionFormFactory $factory;

	protected function createComponentForm(): Form
	{
		$form = $this->factory->build();

		$section = $form->getComponent('section');
		assert($section instanceof Container);
		$section->addText('childInSection');

		$form->onSuccess[] = [$this, 'factorySucceeded'];

		return $form;
	}

	public function factorySucceeded(Form $form): void
	{
		$form['section']['childInSection']->setValue('x');
	}

}
