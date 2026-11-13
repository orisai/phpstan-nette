<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Forms\Form;

final class FactoryBuiltEditForm
{

	private RawFormFactory $formFactory;

	public function __construct(RawFormFactory $formFactory)
	{
		$this->formFactory = $formFactory;
	}

	public function createForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addHidden('id');

		$box = $form->addContainer('box');
		$box->addText('inner');

		$form->addCheckbox('first');
		$form->addCheckbox('second');
		$form->addText('note');

		return $form;
	}

}
