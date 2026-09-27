<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MFinalMethodFormShapeResolves extends Control
{

	final protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('finalField');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['finalField']); // => Nette\Forms\Controls\TextInput
	}

}
