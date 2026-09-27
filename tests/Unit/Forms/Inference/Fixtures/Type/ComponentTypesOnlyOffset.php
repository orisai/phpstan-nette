<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\Testing\assertType;

/**
 * A control added with no value slot - addSubmit(), i.e. every submit button - is recorded in the
 * shape's componentTypes and nowhere else, so it is the one child kind FormShapeProjector::offset()
 * cannot see. Until FormShapeProjector::childType() existed, offsetPath()'s leaf asked only
 * offset(), and every access below fell through to the open shape's mixed or the closed shape's
 * *ERROR* instead of naming the button's class.
 *
 * This is the FormAccessExpressionTypeResolver channel (the forms are built in this file, so
 * FormFileIndex::hasAnyTrackedForm() is true for it).
 */
final class ComponentTypesOnlyOffset
{

	public function plainLeaf(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addSubmit('save', 'Save');

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['save']);
		assertType('Nette\Forms\Controls\TextInput', $form['a']);
	}

	public function separatorJoinedPath(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addSubmit('save', 'Save');

		// The joined and the un-joined spelling are ONE runtime lookup (Nette's
		// Container::getComponent() splits on IComponent::NameSeparator), so they must answer alike:
		// the joined one is the walk's leaf, the un-joined one two offsets each resolved on its own.
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['outer-save']);
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['outer']['save']);
	}

	public function insideReplicatorRow(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $row): void {
			$row->addText('x');
			$row->addSubmit('removeNode', 'Remove');
		});

		// A decimal segment past a replicator is a dynamically created ROW, so this reaches the leaf
		// through FormReplicatorType::pathType()'s row descent - the arm ContainerModel's replicator
		// hop (the channel every .latte goes through) lands in.
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['rows-0-removeNode']);
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['rows'][0]['removeNode']);
		assertType('Nette\Forms\Controls\TextInput', $form['rows-0-x']);
	}

	public function closedShapeFallThroughUnchanged(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addSubmit('save', 'Save');

		// A name NO channel holds is still the closed shape's ErrorType, at a bare leaf and at the end
		// of a path alike - that ErrorType is what carries the proof of absence to
		// FormShapeUnknownAccessRule, and adding the componentTypes channel must not soften it into an
		// IComponent that reports nothing.
		assertType('*ERROR*', $form['nope']);
		assertType('*ERROR*', $form['outer-nope']);

		// A componentTypes-only child is still not a CONTAINER, so a path through one is unresolvable
		// and degrades, exactly as a path through a control does - resolving the button as a leaf does
		// not make it traversable.
		assertType('Nette\ComponentModel\IComponent', $form['outer-save-nope']);
	}

	public function openShapeFallThroughUnchanged(string $name): void
	{
		$form = new ApplicationForm();
		$form->addSubmit('save', 'Save');
		$form->addText($name);

		// An open shape cannot rule the name out, so an unheld name still degrades to mixed - and a
		// name componentTypes DOES hold resolves even though the shape is open.
		assertType('mixed', $form['nope']);
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['save']);
	}

	/**
	 * The MAYBE end of the componentTypes presence axis, and the guard against closing the axis the
	 * naive way. componentTypes records a name from any branch that added it - that union is what
	 * makes childType() resolve the button's own class below - so answering the presence question off
	 * the mere EXISTENCE of an entry flips this case too, and core's MutatingScope::issetCheck()
	 * takes hasOffsetValueType()'s Yes literally. What separates this from the unconditional sibling
	 * is CompositionState::joinComponentTypes() MEETING the certainty beside the class name, not the
	 * entry itself.
	 */
	public function conditionalSubmitStaysNullable(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addSubmit('save', 'Save');
		}

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton|null', $form['save'] ?? null);
	}

	/**
	 * The HAPPENS end, and the whole reason the axis exists. An unconditionally attached submit is
	 * exactly as present as an unconditionally attached text input, so `?? null` must not keep a null
	 * arm for it - Nette's Container::getComponent() throws for an unattached name rather than
	 * returning null, so that arm was never a value the runtime can produce, only a null every later
	 * member access had to be re-narrowed against.
	 *
	 * Before the axis this answered identically to the conditional case one method up, because
	 * componentTypes was the SOLE record of a value-less control and carried no certainty at all.
	 */
	public function unconditionalSubmitIsDefinite(): void
	{
		$form = new ApplicationForm();
		$form->addSubmit('save', 'Save');

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['save'] ?? null);

		// The TYPE axis is independent and unchanged: childType() resolves the button's own class
		// whether or not presence is definite, which is what makes a call on it check at all.
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['save']);
	}

	/**
	 * A second MAYBE source, so the guard does not rest on `if` alone: a loop body is joined against
	 * its own pre-state with an implicit fall-through, so nothing added inside one is ever definite.
	 *
	 * @param list<string> $labels
	 */
	public function loopAddedSubmitStaysNullable(array $labels): void
	{
		$form = new ApplicationForm();
		foreach ($labels as $label) {
			$form->addSubmit('save', $label);
		}

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton|null', $form['save'] ?? null);
	}

	/**
	 * The axis under a path, in both spellings of the one runtime lookup. hasDefinitePath() requires
	 * every hop to be a definitely-attached container AND the leaf to be definite in whatever it
	 * landed in, so this exercises the new arm at the end of a walk rather than at a bare offset.
	 */
	public function unconditionalSubmitInContainerIsDefinite(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addSubmit('save', 'Save');

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['outer-save'] ?? null);
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['outer']['save'] ?? null);
	}

	/**
	 * Its guard: a conditional leaf under an unconditional container. The hop is definite and the
	 * leaf is not, so the path is not - in both spellings.
	 */
	public function conditionalSubmitInContainerStaysNullable(bool $c): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		if ($c) {
			$outer->addSubmit('save', 'Save');
		}

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton|null', $form['outer-save'] ?? null);
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton|null', $form['outer']['save'] ?? null);
	}

	/**
	 * The axis through a REPLICATOR hop, which is FormReplicatorType::hasOffsetValueType()'s own
	 * question rather than FormShapeType's: a non-decimal name past a replicator is one of the
	 * children added straight onto the addDynamic() return value, and the addNode button every Kdyby
	 * replicator template references is exactly such a componentTypes-only own child.
	 *
	 * The bare `$rows['addNode']` spelling is deliberately NOT asserted here. That receiver is a plain
	 * ObjectType over the replicator's class rather than a FormReplicatorType, so its offset question
	 * never reaches the shape at all - an unrelated limitation of the local-variable channel, and one
	 * this axis neither fixes nor worsens.
	 */
	public function unconditionalReplicatorOwnChildIsDefinite(): void
	{
		$form = new ApplicationForm();
		$rows = $form->addDynamic('rows', static function (FormContainer $row): void {
			$row->addText('x');
		});
		$rows->addSubmit('addNode', 'Add');

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['rows-addNode'] ?? null);
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['rows']['addNode'] ?? null);
	}

}
