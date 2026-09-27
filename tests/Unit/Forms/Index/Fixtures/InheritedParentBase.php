<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class InheritedParentBase
{

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$form->addText('parentField');
		$filter = $form->addContainer('filter');
		$filter->addText('inFilter');

		return $form;
	}

}
