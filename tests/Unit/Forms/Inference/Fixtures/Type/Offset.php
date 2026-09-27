<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\Testing\assertType;

final class Offset
{

	public function g1_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('Nette\Forms\Controls\TextInput', $form['a']);
	}

	public function g1_02(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		assertType('Nette\Forms\Controls\TextInput', $form['a']);
	}

	public function g1_03(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		assertType('Nette\Forms\Controls\Checkbox', $form['a']);
	}

	public function g1_04(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		assertType('Nette\Forms\Controls\SelectBox', $form['a']);
	}

	public function g1_05(): void
	{
		$form = new ApplicationForm();
		$form->addRadioList('a');
		assertType('Nette\Forms\Controls\RadioList', $form['a']);
	}

	public function g1_06(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('a');
		assertType('Nette\Forms\Controls\MultiSelectBox', $form['a']);
	}

	public function g1_07(): void
	{
		$form = new ApplicationForm();
		$form->addCheckboxList('a');
		assertType('Nette\Forms\Controls\CheckboxList', $form['a']);
	}

	public function g1_08(): void
	{
		$form = new ApplicationForm();
		$form->addUpload('a');
		assertType('Nette\Forms\Controls\UploadControl', $form['a']);
	}

	public function g1_09(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		assertType('Nette\Forms\Controls\DateTimeControl', $form['a']);
	}

	public function g1_10(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		assertType('Nette\Forms\Controls\HiddenField', $form['a']);
	}

	public function g1_11(): void
	{
		$form = new ApplicationForm();
		$form->addTextArea('a');
		assertType('Nette\Forms\Controls\TextArea', $form['a']);
	}

	public function g1_12(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		assertType('Nette\Forms\Controls\ColorPicker', $form['a']);
	}

	/**
	 * A maybe-present child keeps the plain control type here, NOT `…|null`. `$form['a']` is the
	 * THROWING access - Nette's Container::getComponent() throws for a name that is not attached
	 * rather than returning null - so null was never a value this expression can produce, and the
	 * arm only made every later member access on a conditionally-added control report
	 * method.nonObject / property.nonObject / argument.type against a null that cannot reach it.
	 *
	 * The presence axis is still carried, on its own axis and unchanged: g1_20() below pins that
	 * `?? null` is still nullable here, and a provably absent name is still
	 * FormShapeUnknownAccessRule's report, not a null arm on the value.
	 */
	public function g1_13(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('a');
		}

		assertType('Nette\Forms\Controls\TextInput', $form['a']);
	}

	public function g1_14(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('a');
		} else {
			$form->addInteger('a');
		}

		assertType('Nette\Forms\Controls\TextInput', $form['a']);
	}

	public function g1_15(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addInteger('a');
		assertType('Nette\Forms\Controls\TextInput', $form['a']);
	}

	public function g1_16(): void
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('c');
		$c->addText('a');
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}', $form['c']);
	}

	public function g1_17(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}>', $form['d']);
	}

	public function g1_18(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('*ERROR*', $form['nope']);
	}

	public function g1_19(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText($name);
		assertType('mixed', $form['nope']);
	}

	public function g1_20(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('a');
		}

		assertType('Nette\Forms\Controls\TextInput|null', $form['a'] ?? null);
	}

	/**
	 * The container channel follows g1_13()'s rule: the throwing access keeps the shaped container
	 * type with no null arm, so a path THROUGH a maybe-present container resolves instead of
	 * reporting offsetAccess.notFound on a `FormContainer|null`.
	 */
	public function g1_21(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$inner = $form->addContainer('c');
			$inner->addText('a');
		}

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}', $form['c']);
		assertType('Nette\Forms\Controls\TextInput', $form['c']['a']);
	}

	public function g1_22(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		unset($form['a']);
		assertType('*ERROR*', $form['a']);
	}

	/**
	 * A slot both branches fill with a DIFFERENT control class resolves to the union of the two,
	 * the same answer ContainerModel's own leaf arm - the channel a .latte goes through - already
	 * gave for this shape. It used to resolve to an object type over a class literally named
	 * `\mixed`, which does not exist: every later method call on it was reported as
	 * class.notFound ("Call to method setRequired() on an unknown class \mixed").
	 */
	public function g1_23(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('a');
		} else {
			$form->addTextArea('a');
		}

		assertType('Nette\Forms\Controls\TextArea|Nette\Forms\Controls\TextInput', $form['a']);
		$form['a']->setRequired();
	}

	/**
	 * The presence axis on the CONTAINER channel, which g1_13()'s rule leaves as the only encoding
	 * of it there. The null arm on `?? null` comes from FormShapeType::hasOffsetValueType(), which
	 * asks ComponentPath::hasDefiniteChild() and gets Maybe for a conditionally-added container -
	 * nothing about it went through offset()'s value answer, so dropping the value-axis union did
	 * not touch it.
	 */
	public function g1_24(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$inner = $form->addContainer('c');
			$inner->addText('a');
		}

		assertType(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}|null',
			$form['c'] ?? null,
		);
	}

	/**
	 * The same for the REPLICATOR channel, the third of offset()'s three. An unconditional
	 * replicator answers Yes and keeps no null arm (g1_17() above pins the bare type), a
	 * conditionally-added one answers Maybe and keeps it.
	 */
	public function g1_25(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addDynamic('d', static fn (FormContainer $row) => $row->addText('a'));
		}

		assertType(
			'array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}>',
			$form['d'],
		);
		assertType(
			'array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}>|null',
			$form['d'] ?? null,
		);
	}

	/**
	 * An item factory that declares no type for its row parameter leaves the ROW class unknown, and a
	 * replicator with an unknown row class resolves to the replicator itself, not to a row type over
	 * an invented class. The walk used to call that class `mixed` and hand it to FormShapeType, so the
	 * row came out as `mixed{a: *UNKNOWN*}` - an object type over a class that does not exist, which
	 * core reads literally and reports class.notFound against on every later member access, the same
	 * defect g1_23() removed from the slot channel.
	 *
	 * The degrade is the replicator's own recorded class, so the int offset below falls to that class's
	 * own ArrayAccess<string, IComponent> - byte-identical to the IComponent ContainerModel's leaf arm
	 * has always answered for this shape (ReplicatorUntypedParamStaysIComponent), which declines an
	 * unusable inner shape before it ever asks the projector for a type.
	 */
	public function g1_26(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', static function ($row): void {
			$row->addText('a');
		});

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer', $form['d']);
		assertType('Nette\ComponentModel\IComponent', $form['d'][0]);
	}

	/**
	 * A LATER branch join must not re-promote a container that is already only maybe-attached. The
	 * second `if` here touches neither the form nor 'c', and the join it forces still recomputes
	 * 'c''s presence; before CompositionState::joinMap() met the carried presence, that recompute
	 * saw the key on the pre-state and on every reaching arm and answered HAPPENS - so `?? null`
	 * lost its null arm and isset() below narrowed to a constant true on a container that is
	 * genuinely absent whenever $c is false.
	 *
	 * The trigger is the JOIN, not a mention of the name: an `if`, a `switch`, a `try` or a loop
	 * all force one, and an unconditional read or a bare `isset()` statement forces none.
	 */
	public function g1_27(bool $c, bool $d): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$inner = $form->addContainer('c');
			$inner->addText('a');
		}

		if ($d) {
			$form->addText('unrelated');
		}

		assertType(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}|null',
			$form['c'] ?? null,
		);
		assertType('bool', isset($form['c']));
	}

	/** The replicator channel under the same later join. */
	public function g1_28(bool $c, bool $d): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addDynamic('d', static fn (FormContainer $row) => $row->addText('a'));
		}

		foreach ([1, 2] as $i) {
			$form->addText('unrelated');
		}

		assertType(
			'array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}>|null',
			$form['d'] ?? null,
		);
		assertType('bool', isset($form['d']));
	}

	/**
	 * The same defect with no later statement at all: one arm contributes 'c' only maybe (a nested
	 * `if`), the other contributes it definitely, and every arm therefore HAS the key. Key
	 * existence alone says HAPPENS; the arm's own recorded Maybe is what makes it Maybe.
	 */
	public function g1_29(bool $c, bool $d): void
	{
		$form = new ApplicationForm();
		if ($c) {
			if ($d) {
				$inner = $form->addContainer('c');
				$inner->addText('a');
			}
		} else {
			$other = $form->addContainer('c');
			$other->addText('a');
		}

		assertType(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}|null',
			$form['c'] ?? null,
		);
	}

	/**
	 * The control for g1_27()-g1_29(): a container every arm adds unconditionally IS definitely
	 * attached, and stays so. Meeting the carried presence must not demote a genuine Yes.
	 */
	public function g1_30(bool $c, bool $d): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$inner = $form->addContainer('c');
			$inner->addText('a');
		} else {
			$other = $form->addContainer('c');
			$other->addText('a');
		}

		if ($d) {
			$form->addText('unrelated');
		}

		assertType(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}',
			$form['c'] ?? null,
		);
		assertType('true', isset($form['c']));
	}

}
