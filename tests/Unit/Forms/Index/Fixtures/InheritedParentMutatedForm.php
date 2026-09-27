<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function assert;

final class InheritedParentMutatedForm extends InheritedParentBase
{

	protected function createComponentForm(): Form
	{
		$form = parent::createComponentForm();

		$filter = $form->getComponent('filter');
		assert($filter instanceof Container);
		$filter->addText('childInFilter');

		$form->onSuccess[] = [$this, 'formSucceeded'];

		return $form;
	}

	public function formSucceeded(Form $form): void
	{
		$form['filter']['childInFilter']->setValue('x');
		$form['filter']['inFilter']->setValue('y');
	}

}
