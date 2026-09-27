<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Forms\Controls\BaseControl;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;

final class ExistenceCheck
{

	// EC-01: no error — isset on absent component is an existence check
	public function ec01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		isset($form['nope']);
	}

	// EC-02: no error — empty on absent component is an existence check
	public function ec02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		empty($form['nope']);
	}

	// EC-03: no error — null-coalesce on absent component is an existence check
	public function ec03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['nope'] ?? null;
	}

	// EC-04: no error — isset on absent form value property is an existence check
	public function ec04(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$v = $form->getValues();
		isset($v->nope);
	}

	// EC-05: no error — null-coalesce on absent form value property is an existence check
	public function ec05(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$v = $form->getValues();
		$v->nope ?? null;
	}

	// EC-06: Form component 'nope' does not exist. [orisaiNette.forms.noSuchComponent] — plain read must still error
	public function ec06(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['nope'];
	}

	// EC-07: Form component 'nope' does not exist. — getComponent() is the throwing access, like $form['nope']
	public function ec07(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->getComponent('nope');
	}

	// EC-08: Form component 'nope' does not exist. — offsetGet() is the throwing access too
	public function ec08(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->offsetGet('nope');
	}

	// EC-09: no error — getComponent($name, false) is the no-throw existence check (returns null)
	public function ec09(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->getComponent('nope', false);
	}

	// EC-10: no error — offsetExists() is an existence check, never throws
	public function ec10(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->offsetExists('nope');
	}

	// EC-11: no error — getComponent() of an existing component
	public function ec11(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->getComponent('a');
	}

	// EC-12: no error — addSubmit() contributes no value but the component exists
	public function ec12(): void
	{
		$form = new ApplicationForm();
		$form->addSubmit('save', 'Save');
		$form['save'];
	}

	// EC-13: Form value 'save' does not exist. [orisaiNette.forms.noSuchComponent] — a submit is
	// KIND_OMITTED, so a VALUES access still finds nothing to read, even though the component
	// itself exists
	public function ec13(): void
	{
		$form = new ApplicationForm();
		$form->addSubmit('save', 'Save');
		$v = $form->getValues();
		$v->save;
	}

	// EC-14: no error — a bare replicator leaf is a FormReplicatorType, not a FormShapeType/
	// FormValuesObjectShapeType, so this rule never classifies it; a runtime int row read is left
	// to the core offsetAccess.notFound check instead
	public function ec14(int $i): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$form['rows'][$i];
	}

	// EC-15: no error — a control added straight onto the replicator holder (the addDynamic()
	// return value) exists as an own child, not a row
	public function ec15(): void
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$form['rows']['addNode'];
	}

	// EC-16: Form component 'nope' does not exist. [orisaiNette.forms.noSuchComponent] — an unknown string
	// offset on a replicator whose own-children set is closed is reported exactly once by OUR rule
	public function ec16(): void
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$form['rows']['nope'];
	}

	// EC-17: no error — Nette's Container::getComponent() splits a name on '-' and descends
	// recursively, so $form['outer']['rep-addNode'] is the same lookup as
	// $form['outer']['rep']['addNode'] and must resolve rather than reporting noSuchComponent
	public function ec17(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$rep = $outer->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$form['outer']['rep-addNode'];
	}

	// EC-18: no error — the same own child, reached through a variable whose OWN type is a
	// FormReplicatorType directly (assigned from $form['rows'], not nested under a FormShapeType
	// receiver at the access point) — $rep itself (the addDynamic() return value) is a plain
	// declared class, never FormReplicatorType; only re-fetching through the tracked form's own
	// offset access carries that wrapper type
	public function ec18(): void
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$holder = $form['rows'];
		$holder['addNode'];
	}

	// EC-19: Form component 'nope' does not exist. — same FormReplicatorType-typed receiver, an
	// unknown own-child name still reports
	public function ec19(): void
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$holder = $form['rows'];
		$holder['nope'];
	}

	// EC-20: no error — a purely-numeric name on a replicator holder is indistinguishable from a
	// dynamically created row's own component name (Kdyby names each row by its integer index,
	// cast to a string key), so it degrades rather than being misreported as a missing own child
	public function ec20(): void
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$holder = $form['rows'];
		$holder['0'];
	}

	// EC-21: no error — a control chained straight onto the addDynamic() return value, in the SAME
	// statement, with no variable capture at all; the own shape must open rather than close empty
	public function ec21(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		})->addSubmit('addNode', 'Add');
		$form['rows']['addNode'];
	}

	// EC-22: no error — the replicator added as its own bare (uncaptured) statement, then a control
	// added via the offset-access chain form rather than a captured variable
	public function ec22(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$form['rows']->addSubmit('addNode', 'Add');
		$form['rows']['addNode'];
	}

	// EC-23: no error — EC-17's separator descent, but the nested replicator's own child is added via
	// a chained/uncaptured call rather than a captured variable
	public function ec23(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('x');
		})->addSubmit('addNode', 'Add');
		$form['outer']['rep-addNode'];
	}

	// EC-24: no error — the same uncaptured holder, reached via getComponent() instead of array access
	public function ec24(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		})->addSubmit('addNode', 'Add');
		$form['rows']->getComponent('addNode');
	}

	// EC-25: Form component 'rep-nope' does not exist. [orisaiNette.forms.noSuchComponent] — the
	// separator-path message names the FULL name the user wrote, not just the last segment
	public function ec25(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$rep = $outer->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');
		$form['outer']['rep-nope'];
	}

	// EC-26: no error — a replicator added by chaining addDynamic() straight onto addContainer()'s
	// return, in ONE statement (no intermediate variable holds the plain container itself; the
	// assigned variable is bound to the REPLICATOR, exactly like $rep above). The container
	// reference escaped without ever being captured on its own, so 'items' nested inside 'outer2'
	// must open rather than read as absent.
	public function ec26(): void
	{
		$form = new ApplicationForm();
		$rep = $form->addContainer('outer2')
			->addDynamic('items', static function (FormContainer $c): void {
				$c->addText('x');
			});
		$rep->addSubmit('addNode', 'Add');
		$form['outer2']['items'];
	}

	// EC-27: no error — EC-26's chained add with NOTHING captured at all: a bare statement, so the
	// assign the EC-26 guard needs is absent. 'z' is registered on a container this walk cannot
	// follow, so the container must open rather than read as empty.
	public function ec27(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('sub')->addText('z');
		$form['sub']['z'];
	}

	// EC-28: Form component 'nope' does not exist. [orisaiNette.forms.noSuchComponent] — the counterpart
	// EC-27 must not swallow. An add* call that IS the whole statement drops the container reference
	// on the floor, so nothing can ever have added to it and the closed empty shape is a proof.
	public function ec28(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('sub');
		$form['sub']['nope'];
	}

	// EC-29: no error — the container reference escapes into a callee instead of a chained add. The
	// walk does not read what the callee does with it, so 'h' is not provably absent.
	public function ec29(): void
	{
		$form = new ApplicationForm();
		$this->configure($form->addContainer('held'));
		$form['held']['h'];
	}

	// EC-30: no error — a replicator whose item factory is not an inline closure. The ROW's children
	// cannot be enumerated at all, so a row field must not read as absent.
	public function ec30(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep30', [$this, 'makeRow']);
		$form['rep30'][0]['q'];
	}

	// EC-31: Form component 'nope' does not exist. [orisaiNette.forms.noSuchComponent] — a LEAF control
	// pulled into a local and only modified through it. The handle cannot register anything (a
	// TextInput has no add* surface) and every use of it is a call that registers nothing, so the
	// shape stays closed and absence is still reportable. The same component set written
	// $form->addText('a')->setDisabled() has always reported here, and the two spellings must agree.
	public function ec31(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form['a'];
		$probe->setDisabled();
		$form['nope'];
	}

	// EC-32: no error — the safe floor by USE: the same leaf handle handed to a callee. What the
	// callee does with it is not read, so the form may gain a field through it and nothing on the
	// shape is provably absent.
	public function ec32(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form['a'];
		$this->tweak($probe);
		$form['nope'];
	}

	// EC-33: no error — the safe floor by TYPE: a CONTAINER pulled into a local. It can gain fields
	// whatever is called on it, so the read opens the shape exactly as it always has.
	public function ec33(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('sub');
		$holder = $form['sub'];
		$holder->setDefaults([]);
		$form['nope'];
	}

	public function makeRow(FormContainer $row): void
	{
		$row->addText('q');
	}

	private function configure(FormContainer $container): void
	{
		$container->addText('h');
	}

	private function tweak(BaseControl $control): void
	{
		$control->setDisabled();
	}

}
