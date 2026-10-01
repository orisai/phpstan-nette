<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class CmGetComponentsIterable extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addText('email');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getComponents()); // => iterable<int|string, Nette\Forms\Controls\TextInput>
		dumpType($this['form']->getControls()); // => Iterator<int|string, Nette\Forms\Controls\TextInput>
		$form = $this['form'];
		dumpType($form->getComponents()); // => iterable<int|string, Nette\Forms\Controls\TextInput>
	}

}
