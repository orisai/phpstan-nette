<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support;

use Nette\Forms\Form;

final class FmChainFactory
{

	public function assemble(): Form
	{
		$form = new Form();
		$form->addText('city');

		return $form;
	}

}
