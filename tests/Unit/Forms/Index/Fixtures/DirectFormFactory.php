<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class DirectFormFactory
{

	public function create(): FactoryBuiltForm
	{
		return new FactoryBuiltForm();
	}

	public function build(): PlainFactoryForm
	{
		$form = new PlainFactoryForm();
		$form->addText('fromBuild');

		return $form;
	}

}
