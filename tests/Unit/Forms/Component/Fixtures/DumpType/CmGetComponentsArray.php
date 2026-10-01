<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Container;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class CmGetComponentsArray extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addText('email');

		return $form;
	}

	public function go(Container $unknown): void
	{
		dumpType($this['form']->getComponents()); // => array<int|string, Nette\Forms\Controls\TextInput>
		dumpType($this['form']->getControls()); // => iterable<int|string, Nette\Forms\Controls\TextInput>
		$form = $this['form'];
		dumpType($form->getComponents()); // => array<int|string, Nette\Forms\Controls\TextInput>
		dumpType($unknown->getComponents()); // => array<int|string, Nette\ComponentModel\IComponent>
		$this['form']->getControls()->current(); // !! Cannot call method current() on iterable<int|string, Nette\Forms\Controls\TextInput>.
	}

}
