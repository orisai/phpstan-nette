<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class DoubleRebindBase
{

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$form->addText('fromParent');

		return $form;
	}

}
