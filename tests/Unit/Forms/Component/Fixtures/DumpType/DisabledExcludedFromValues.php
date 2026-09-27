<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class DisabledExcludedFromValues extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addText('b')->setDisabled();
		$form->addText('c')->setOmitted();
		$form->addText('d')->setDisabled(false);
		$form->addText('e')->setDisabled()->setOmitted(false);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{a: string, d: string, e: string}
		dumpType($this['form']->getValues(true)); // => array{a: string, d: string, e: string}
		dumpType($this['form']['b']); // => Nette\Forms\Controls\TextInput
	}

}
