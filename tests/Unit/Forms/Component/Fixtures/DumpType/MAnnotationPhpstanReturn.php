<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class MAnnotationPhpstanReturn extends Control
{

	/** @phpstan-return ApplicationForm */
	protected function createComponentForm(): Form
	{
		$form = new ApplicationForm();
		$form->addText('annField');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['annField']); // => Nette\Forms\Controls\TextInput
	}

}
