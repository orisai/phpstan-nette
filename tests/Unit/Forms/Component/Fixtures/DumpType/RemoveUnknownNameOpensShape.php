<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\ComponentModel\IComponent;
use function PHPStan\dumpType;

final class RemoveVariableComponentOpensShape extends Control
{

	private IComponent $widget;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('present');
		$form->removeComponent($this->widget);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{present: string, ...<mixed>}
	}

}

final class UnsetDynamicKeyOpensShape extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('present');
		$key = $this->dynamicKey();
		unset($form[$key]);

		return $form;
	}

	private function dynamicKey(): string
	{
		return 'present';
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{present: string, ...<mixed>}
	}

}
