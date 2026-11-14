<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Container;
use Nette\Forms\Control as FormControl;
use Nette\Forms\Form;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function assert;
use function PHPStan\dumpType;

/**
 * A helper that only reads a child by a dynamic name and returns it, used as the target of a condition,
 * and a bound control used as a condition target, both leave the shape closed and the control typed.
 */
final class MPassiveReaderHelperKeepsShapeClosed extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addSelect('nationality', null, ['CZ' => 'CZ']);
		$has = $form->addRadioList('has', null, ['y' => 'Y', 'n' => 'N']);
		$has->setRequired()->addCondition(Form::Equal, 'y')->toggle('birth');
		$nationality = $this->getControl($form, 'nationality');
		$form->addText('birth')
			->addConditionOn($nationality, Form::Equal, 'CZ')
			->addConditionOn($has, Form::Equal, 'y')
			->setRequired();

		return $form;
	}

	protected function createComponentMutated(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addContainer('box');
		$box = $this->getContainer($form, 'box');
		$box->addText('late');

		return $form;
	}

	private function getControl(ApplicationForm $form, string $name): FormControl
	{
		$control = $form[$name];
		assert($control instanceof FormControl); // @phpstan-ignore function.alreadyNarrowedType, instanceof.alwaysTrue

		return $control;
	}

	private function getContainer(ApplicationForm $form, string $name): Container
	{
		$container = $form[$name];
		assert($container instanceof Container); // @phpstan-ignore function.alreadyNarrowedType, instanceof.alwaysTrue

		return $container;
	}

	public function go(): void
	{
		$form = $this['form'];
		dumpType($form->getValues('array')); // => array{nationality: 'CZ'|null, has: 'n'|'y'|null, birth: string}
		$form['nope']->setRequired(); // !! Form component 'nope' does not exist.

		$mutated = $this['mutated'];
		dumpType($mutated->getValues('array')); // => array{box: array{}, ...<string, mixed>}
	}

}
