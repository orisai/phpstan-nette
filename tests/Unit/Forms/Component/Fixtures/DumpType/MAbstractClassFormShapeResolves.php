<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

abstract class MAbstractClassFormShapeResolves extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('abstractField');

		return $form;
	}

	public function go(): void
	{
		$control = $this['form']['abstractField'];
		dumpType($control); // => Nette\Forms\Controls\TextInput
	}

}
