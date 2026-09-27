<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

final class MScopeVarClosureDoesNotPollute extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('first');
		$field = 'realname';
		$form->addText($field);
		$form->onSuccess[] = function (): void {
			$field = 'shadow';
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{first: string, realname: string}
	}

}

final class MScopeVarCompoundAssignStaysUnresolved extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$a = 'first';
		$form->addText($a);
		$key = 'pre';
		$key .= '_suf';
		$form->addText($key);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{first: string, ...<mixed>}
	}

}

final class MScopeVarAddComponentNameResolved extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$name = 'custom';
		$form->addComponent(new TextInput(), $name);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{custom: string}
	}

}
