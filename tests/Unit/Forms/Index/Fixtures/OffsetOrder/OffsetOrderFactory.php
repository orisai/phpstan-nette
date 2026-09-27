<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\OffsetOrder;

use Nette\Application\UI\Form;

final class OffsetOrderFactory
{

	public function create(): Form
	{
		$form = new Form();
		$form->addText('field');

		return $form;
	}

}
