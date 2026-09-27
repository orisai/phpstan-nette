<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Forms\Container;
use function PHPStan\dumpType;

final class AddComponentFoldsPopulatedContainer extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$c = new Container();
		$c->addText('inner')->setRequired();
		$form->addComponent($c, 'sub');

		return $form;
	}

	public function viaAddComponent(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{inner: string}}
		dumpType($this['form']['sub']['inner']); // => Nette\Forms\Controls\TextInput
		if ($this['form']->isValid()) {
			dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{inner: non-empty-string}}
		}
	}

}

final class AddComponentFoldEqualsOffsetSet extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$c = new Container();
		$c->addText('inner')->setRequired();
		$form['sub'] = $c;

		return $form;
	}

	public function viaOffsetSet(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{inner: string}}
		dumpType($this['form']['sub']['inner']); // => Nette\Forms\Controls\TextInput
		if ($this['form']->isValid()) {
			dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{inner: non-empty-string}}
		}
	}

}
