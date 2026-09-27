<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\Support;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class OpaqueContentControl extends BaseOpaqueContentControl
{

	public function create(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('title')->setRequired();
		$form->addInteger('rank');

		return $form;
	}

}
