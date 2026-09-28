<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class NewsManageRenderer extends PairingControl
{

	public function createComponentNewsForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('title');
		$form->addSubmit('save');

		return $form;
	}

}
