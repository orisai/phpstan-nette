<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use Nette\Forms\Container;
use function OriPhpstan\Nette\Forms\Testing\dumpComponent;

final class FlatFormControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name')->setRequired();
		$form->addInteger('age');
		$form->addSubmit('save');

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}

final class NestedContainerControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$address = $form->addContainer('address');
		$address->addText('city');
		$address->addText('zip');

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}

final class ReplicatorControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('items', static function (FormContainer $item): void {
			$item->addText('label');
		});

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}

final class ControlWithFormDump extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this);
	}

}

final class FormWithNonFormSubcomponentControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addComponent(new MyNonFormComponent(), 'widget');

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}

final class FormContainerDump extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$sub = $form->addContainer('sub');
		$sub->addText('inner')->setRequired();

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']['sub']);
	}

}

final class DumpParamsControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name')->setRequired();
		$address = $form->addContainer('address');
		$address->addText('city');
		$form->addDynamic('items', static function (FormContainer $c): void {
			$c->addText('label');
		});

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form'], 0);
		dumpComponent($this['form'], 1);
		dumpComponent($this['form'], null, false);
	}

}

final class AddViaMethods extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$addr = $form->addContainer('addr');
		$addr->addText('city');
		$form->addDynamic('items', static function (FormContainer $item): void {
			$item->addText('label');
		});

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}

final class AddViaOffset extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form['name'] = new \Nette\Forms\Controls\TextInput();
		$addr = new FormContainer();
		$addr->addText('city');
		$form['addr'] = $addr;
		$form['items'] = new \Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer(static function (FormContainer $item): void {
			$item->addText('label');
		});

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}

final class AddViaAddComponent extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addComponent(new \Nette\Forms\Controls\TextInput(), 'name');
		$addr = new FormContainer();
		$addr->addText('city');
		$form->addComponent($addr, 'addr');
		$form->addComponent(new \Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer(static function (FormContainer $item): void {
			$item->addText('label');
		}), 'items');

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}
