<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

/**
 * A bare handler-param form variable is narrowed to the class the form is actually registered with,
 * so the corpus-typical BASE `Form $form` signature (onOk) now renders every family concrete at both
 * the handler-param and the same-file class-component vantage — the walk that reflects the builder's
 * add* returns reflects them on the constructed ApplicationForm, not on the declared base Form, and
 * the shared summaries the base-declared handler would otherwise coarsen recover transitively. A
 * DERIVED `ApplicationForm $form` signature (onOkDerived) is the control: it already rendered concrete
 * from its declared type and is unchanged (equal type, not narrowed). A base Nette form's container
 * stays base — the narrowing only fires for a strict subtype, so the sound boundary holds.
 *
 * The two class-component vantages render the whole shape rather than the bare class: a form-level
 * access carries its FormShapeType now, which is what puts the form's own fields on the receiver a
 * template reads. A handler PARAM is a different question and still renders bare — its narrowing is
 * to the registered CLASS, not to a shape.
 */
final class ScopeFreeConcreteLabelsResolve extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$filter = $form->addContainer('filter');
		$filter->addText('note');
		$form->addSubmit('send', 'Send');
		$form->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$form->onSuccess[] = [$this, 'onOk'];
		$form->onSuccess[] = [$this, 'onOkDerived'];

		return $form;
	}

	protected function createComponentDeclaredBase(): Form
	{
		$form = new ApplicationForm();
		$form->addText('a');

		return $form;
	}

	protected function createComponentBaseForm(): Form
	{
		$form = new Form();
		$grp = $form->addContainer('grp');
		$grp->addText('b');

		return $form;
	}

	public function onOk(Form $form): void
	{
		dumpType($form); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm
		dumpType($form['filter']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{note: string}
		dumpType($form['send']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
		dumpType($form['rep']); // => array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>
	}

	public function onOkDerived(ApplicationForm $form): void
	{
		dumpType($form); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm
		dumpType($form['filter']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{note: string}
		dumpType($form['send']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
		dumpType($form['rep']); // => array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>
	}

	public function go(): void
	{
		dumpType($this['form']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{filter: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{note: string}, rep: array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>+own{…+unknown(container_reference)}}
		dumpType($this['form']['filter']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{note: string}
		dumpType($this['form']['send']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
		dumpType($this['form']['rep']); // => array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>
		dumpType($this['declaredBase']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string}
		dumpType($this['baseForm']['grp']); // => Nette\Forms\Container{b: string}
	}

}
