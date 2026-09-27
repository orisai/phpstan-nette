<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Nette\Forms\Form as NetteForm;

final class ClassTrackerChildControl extends ClassTrackerBaseControl
{

	protected function createComponentForm(): NetteForm
	{
		$form = $this->formFactory->create();
		$form->addHidden('id');

		return $form;
	}

}
