<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

final class SectionFormFactory
{

	public function build(): Form
	{
		$form = new Form();
		$form->addText('fromFactory');
		$section = $form->addContainer('section');
		$section->addText('inSection');

		return $form;
	}

}
