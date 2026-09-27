<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

final class MInlineScopeVarNamesSingle extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$name = 'email';
		$form->addText($name)->setRequired();
		$form->addInteger('age');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{email: string, age: int|null}
	}

}

final class MInlineScopeVarNamesInterleaved extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$a = 'one';
		$form->addText($a);
		$b = 'two';
		$form->addText($b);

		return $form;
	}

	public function goInterleaved(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{one: string, two: string}
	}

}

final class MInlineScopeVarNamesOffsetReuse extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$foo = 'foo';
		$form->addText($foo);
		$form[$foo] = new TextInput();

		return $form;
	}

	public function goOffsetReuse(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{foo: string}
	}

}
