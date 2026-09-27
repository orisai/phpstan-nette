<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

class MLocalVariableParentCallBase extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('parentLocal');

		return $form;
	}

}

final class MLocalVariableParentCallShapeInherits extends MLocalVariableParentCallBase
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = parent::createComponentForm();
		$form->addText('childLocal');

		dumpType($form['parentLocal']); // => Nette\Forms\Controls\TextInput

		return $form;
	}

}
