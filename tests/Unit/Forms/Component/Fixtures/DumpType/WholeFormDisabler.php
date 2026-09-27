<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class WholeFormDisabler extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$sub = $form->addContainer('sub');
		$sub->addText('x');
		$form->setDisabled();

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{}}
		dumpType($this['form']->getValues(true)); // => array{sub: array{}}
		dumpType($this['form']['a']); // => Nette\Forms\Controls\TextInput
		dumpType($this['form']['sub']['x']); // => Nette\Forms\Controls\TextInput
	}

}
