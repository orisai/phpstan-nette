<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

class MParentCreateComponentInheritsShapeBase extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('parentField');

		return $form;
	}

}

final class MParentCreateComponentInheritsShape extends MParentCreateComponentInheritsShapeBase
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = parent::createComponentForm();
		$form->addText('childField');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['childField']); // => Nette\Forms\Controls\TextInput
		dumpType($this['form']['parentField']); // => Nette\Forms\Controls\TextInput
	}

}
