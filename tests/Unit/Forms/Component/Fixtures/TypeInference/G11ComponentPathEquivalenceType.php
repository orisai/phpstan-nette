<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function PHPStan\Testing\assertType;

/**
 * The four spellings of ONE runtime lookup must resolve to ONE type. Nette's
 * Container::getComponent() splits a name on IComponent::NameSeparator and descends
 * (vendor/nette/component-model/src/ComponentModel/Container.php:116) and
 * ComponentModel\ArrayAccess::offsetGet() delegates straight to it, so $form['a-b'], $form['a']['b'],
 * getComponent('a-b') and getComponent('a')->getComponent('b') are indistinguishable at runtime.
 *
 * This is the FormAccessExpressionTypeResolver channel (the form is built in this file, so
 * FormFileIndex::hasAnyTrackedForm() is true for it); G10ReplicatorOwnChildWalkType pins the same
 * equivalence on the ContainerModel walk channel a .latte uses.
 */
final class G11ComponentPathEquivalenceType
{

	public function nestedContainer(): void
	{
		$form = new Form();
		$outer = $form->addContainer('outer');
		$mid = $outer->addContainer('mid');
		$mid->addText('a');

		assertType('Nette\Forms\Controls\TextInput', $form['outer']['mid']['a']);
		assertType('Nette\Forms\Controls\TextInput', $form['outer-mid-a']);
		assertType('Nette\Forms\Controls\TextInput', $form['outer-mid']['a']);
		assertType('Nette\Forms\Controls\TextInput', $form['outer']['mid-a']);
	}

	public function replicatorRows(): void
	{
		$form = new Form();
		$rows = $form->addDynamic('rows', static function (Container $row): void {
			$row->addText('x');
		});
		$rows->addSubmit('addNode', 'Add');

		// A replicator's rows are created on demand and keyed by integer index. Nette casts an int
		// offset to a string before looking it up (ComponentModel\ArrayAccess::offsetGet) and
		// NameRegexp accepts digits, so the string and the int spellings are the same component.
		assertType('Nette\Forms\Container{x: string}', $form['rows'][0]);
		assertType('Nette\Forms\Container{x: string}', $form['rows']['0']);
		assertType('Nette\Forms\Controls\TextInput', $form['rows'][0]['x']);
		assertType('Nette\Forms\Controls\TextInput', $form['rows']['0-x']);
		assertType('Nette\Forms\Controls\TextInput', $form['rows-0-x']);

		// A NON-decimal segment under a replicator is one of its OWN children instead - a control
		// added straight onto addDynamic()'s return value, never a row field.
		assertType('Nette\Forms\Controls\SubmitButton', $form['rows']['addNode']);
		assertType('Nette\Forms\Controls\SubmitButton', $form['rows-addNode']);

		// A name a CLOSED row shape proves absent is an ErrorType to the projector, which is not an
		// ANSWER - the replicator degrades to the wrapped class's own ArrayAccess stub rather than
		// letting *ERROR* escape into an offset the shape never claimed.
		assertType('Nette\ComponentModel\IComponent', $form['rows']['0-nope']);
		assertType('Nette\ComponentModel\IComponent', $form['rows-0-nope']);

		// The row shape and the own shape are different name sets: an own child's name is never
		// looked for in a row and a row field's name never in the own shape.
		assertType('Nette\ComponentModel\IComponent', $form['rows']['0-addNode']);
		assertType('Nette\ComponentModel\IComponent', $form['rows']['x']);
	}

	public function degradesRatherThanGuesses(): void
	{
		$form = new Form();
		$outer = $form->addContainer('outer');
		$outer->addText('a');

		// An empty segment can never name a component (Container::addComponent() applies the same
		// NameRegexp getComponent() checks), so the path is unresolvable as a TYPE - degrade to the
		// wrapped class's own ArrayAccess stub answer rather than guess. FormShapeUnknownAccessRule
		// stays the one authority that REPORTS it.
		assertType('Nette\\ComponentModel\\IComponent', $form['outer-']);
		assertType('Nette\\ComponentModel\\IComponent', $form['-a']);
		assertType('Nette\\ComponentModel\\IComponent', $form['outer--a']);

		// The resolvable spellings, for contrast: a real path resolves, and a name a CLOSED shape
		// proves absent is the projector's ErrorType, which FormShapeUnknownAccessRule reports.
		assertType('Nette\\Forms\\Container{a: string}', $form['outer']);
		assertType('*ERROR*', $form['nope']);

		// A known-but-not-traversable intermediate segment degrades too: 'a' is a control, and the
		// shape carries no reflection to prove a control class is not itself an IContainer.
		assertType('Nette\\ComponentModel\\IComponent', $form['a-x']);
	}

}
