<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Forms\Controls\BaseControl;
use function assert;

final class ConstructorMutatedForm
{

	protected function createComponentForm(): ConstructorFieldForm
	{
		$form = new ConstructorFieldForm();

		$probe = $form->getComponent('fromCtor');
		assert($probe instanceof BaseControl);
		$probe->setDisabled();

		$form->onSuccess[] = [$this, 'ctorSucceeded'];

		return $form;
	}

	public function ctorSucceeded(ConstructorFieldForm $form): void
	{
	}

}
