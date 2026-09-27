<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MReassignTrackedFormResets extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new RawForm();
		$form->addText('x');
		$form = new ApplicationForm();
		$form->addText('z');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{z: string}
	}

}
