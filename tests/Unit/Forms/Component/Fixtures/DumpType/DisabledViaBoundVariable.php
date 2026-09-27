<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class DisabledViaBoundVariable extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$secret = $form->addText('secret');
		$secret->setDisabled();

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{a: string}
	}

}
