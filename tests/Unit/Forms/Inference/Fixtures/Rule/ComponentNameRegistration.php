<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Forms\Controls\TextInput;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Ublaboo\DataGrid\DataGrid;

final class ComponentNameRegistration
{

	public function validNames(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addText('user_name');
		$form->addText('field2');
		$form->addSubmit('SAVE');
		$form->addContainer('address')->getComponents();
	}

	public function separatorInName(): void
	{
		// The `-` is IComponent::NameSeparator: legal inside a lookup PATH, rejected as a name.
		$form = new ApplicationForm();
		$form->addText('first-name');
	}

	public function spaceInName(): void
	{
		$form = new ApplicationForm();
		$form->addText('bad name');
	}

	public function emptyName(): void
	{
		$form = new ApplicationForm();
		$form->addText('');
	}

	public function nonRegisteringAddMethodsAreNotJudged(): void
	{
		$form = new ApplicationForm();
		$form->addText('ok')->addRule($form::MinLength, 'too short', 3);
		$form->addGroup('a group caption');
		$form->addError('a message with spaces');
	}

	public function addComponentTakesItsNameSecond(): void
	{
		$form = new ApplicationForm();
		$form->addComponent(new TextInput(), 'bad name');
	}

	public function containerAndReplicatorNames(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('bad name');
	}

	public function nonConstantNameDegradesSilently(string $dynamic, int $index): void
	{
		$form = new ApplicationForm();
		$form->addText($dynamic);
		$form->addText('row' . $index);
	}

	public function integerNameIsAlwaysValid(): void
	{
		$form = new ApplicationForm();
		$form->addContainer(0);
	}

	/**
	 * DataGrid is a Nette\Application\UI\Control, so a container, and it calls its human-readable
	 * COLUMN TITLE `$name` — the exact shape that produced 63 false positives on this project's
	 * corpus before the rule also required the method to return the component it registered.
	 * Ublaboo's columns and actions are plain objects, never IComponents.
	 */
	public function aTitleParameterNamedNameIsNotAComponentName(): void
	{
		$grid = new DataGrid();
		$grid->addColumnText('id', 'ID faktury');
		$grid->addAction('edit', '');
	}

}
