<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;

/**
 * Nette's Container::getComponent() splits a name on IComponent::NameSeparator and descends, so
 * $form['a-b'], $form['a']['b'], getComponent('a-b') and getComponent('a')->getComponent('b') are
 * ONE lookup. Every row below pins a spelling of that lookup against what the vendor really does.
 *
 * Containers are captured into a variable rather than chained: an uncaptured chained call escapes
 * the container and opens its shape (a separate, deliberate behaviour), which would hide the
 * absence axis these rows are about.
 */
final class ComponentPathAccess
{

	// CP-01: Form component 'nope-x' does not exist. [orisai.nette.forms.noSuchComponent] — THE DETECTION
	// LOSS this consolidation closed: an intermediate segment in no channel of a CLOSED shape is the
	// same proven absence as a missing leaf ($form['nope'] on the next row), and Nette throws
	// "Component with name 'nope' does not exist" for both. Reporting nothing here was the walk
	// bailing on an unresolvable path when it had in fact resolved one.
	public function cp01(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form['nope-x'];
	}

	// CP-02: Form component 'nope' does not exist. [orisai.nette.forms.noSuchComponent] — the un-joined
	// spelling of CP-01's first hop, which always reported
	public function cp02(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form['nope'];
	}

	// CP-03: Form component 'outer-nope' does not exist. [orisai.nette.forms.noSuchComponent] — a resolvable
	// hop with a missing LEAF; the message names the FULL name the author wrote
	public function cp03(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form['outer-nope'];
	}

	// CP-04: no error — the same path, both segments real
	public function cp04(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form['outer-a'];
	}

	// CP-05: no error — three real segments, and the un-joined spellings of the same lookup
	public function cp05(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$mid = $outer->addContainer('mid');
		$mid->addText('a');

		$form['outer-mid-a'];
		$form['outer']['mid']['a'];
		$form['outer-mid']['a'];
		$form['outer']['mid-a'];
		$form->getComponent('outer-mid-a');
		$form->getComponent('outer')->getComponent('mid-a');
	}

	// CP-06: Form component 'outer-mid-nope' does not exist. [orisai.nette.forms.noSuchComponent]
	public function cp06(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$mid = $outer->addContainer('mid');
		$mid->addText('a');
		$form['outer-mid-nope'];
	}

	// CP-07: Form component 'outer-nope-a' does not exist. [orisai.nette.forms.noSuchComponent] — the missing
	// segment is in the MIDDLE, and the shape holding it is closed
	public function cp07(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$mid = $outer->addContainer('mid');
		$mid->addText('a');
		$form['outer-nope-a'];
	}

	// CP-08: no error — an intermediate segment that IS a known child but not a container. Nette
	// throws "is not container and cannot have 'x' component" at runtime, but the shape carries no
	// reflection to prove a control class is not itself an IContainer, so this degrades.
	public function cp08(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a-x'];
	}

	// CP-09: Form value 'nope-x' may not exist; the form shape is open. [orisai.nette.forms.unknownAccess]
	// (tip: Form shape opened by: dynamic_name) — the same missing intermediate as CP-01 on an OPEN
	// shape degrades to the openness message, exactly as the un-joined $form['nope'] does
	public function cp09(string $dynamic): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form->addText($dynamic);
		$form['nope-x'];
	}

	// CP-10: Form component path 'outer-' has an invalid segment ''; a component name must be a
	// non-empty alphanumeric string. [orisai.nette.forms.shapeInvalidComponentName] — the empty segment a trailing
	// separator produces can never be in $components (addComponent() applies the same NameRegexp),
	// so getComponent() throws no matter what the container holds
	public function cp10(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form['outer-'];
	}

	// CP-11: Form component path '-a' has an invalid segment ''; a component name must be a
	// non-empty alphanumeric string. [orisai.nette.forms.shapeInvalidComponentName]
	public function cp11(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['-a'];
	}

	// CP-12: Form component path 'outer--a' has an invalid segment ''; a component name must be a
	// non-empty alphanumeric string. [orisai.nette.forms.shapeInvalidComponentName]
	public function cp12(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form['outer--a'];
	}

	// CP-13: Form component path '-' has an invalid segment ''; a component name must be a non-empty
	// alphanumeric string. [orisai.nette.forms.shapeInvalidComponentName] — a name that is only the separator
	public function cp13(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['-'];
	}

	// CP-14: Form component path 'nope-' has an invalid segment ''; a component name must be a
	// non-empty alphanumeric string. [orisai.nette.forms.shapeInvalidComponentName] — the invalid segment is
	// reported even though the vendor would fail on 'nope' first: BOTH throw, and validation is
	// eager because an invalid segment can never be reached without every earlier one resolving
	public function cp14(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['nope-'];
	}

	// CP-15: Form component path 'nope-' has an invalid segment ''; a component name must be a
	// non-empty alphanumeric string. [orisai.nette.forms.shapeInvalidComponentName] — and it reports on an OPEN
	// shape too, the ONE arm of this rule that does not need the shape closed: no build step the
	// analyser failed to enumerate could ever have registered an empty name
	public function cp15(string $dynamic): void
	{
		$form = new ApplicationForm();
		$form->addText($dynamic);
		$form['nope-'];
	}

	// CP-16: Form component 'a b' does not exist. [orisai.nette.forms.noSuchComponent] — an invalid SINGLE
	// name is still classified by absence, exactly as before: with one segment "invalid" and
	// "absent" are the same failed lookup and the absence machinery already owns it
	public function cp16(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a b'];
	}

	// CP-17: no error — a decimal FIRST segment under a replicator names a dynamically created ROW
	// (Kdyby names each replica by its integer index, which Nette casts to a string key), so
	// $rep['0-x'] really is row 0's 'x' and is not an own child that could be missing
	public function cp17(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});

		$form['rows']['0-x'];
		$form['rows-0-x'];
		$form['rows']['0'];
		$form['rows'][0]['x'];
	}

	// CP-18: Form component 'rows-nope' does not exist. [orisai.nette.forms.noSuchComponent] — a NON-decimal
	// first segment under a replicator is an own child, and a closed own shape proves its absence
	public function cp18(): void
	{
		$form = new ApplicationForm();
		$rows = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rows->addSubmit('addNode', 'Add');

		$form['rows-nope'];
	}

	// CP-19: no error — the own child that IS there, in both spellings
	public function cp19(): void
	{
		$form = new ApplicationForm();
		$rows = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});
		$rows->addSubmit('addNode', 'Add');

		$form['rows-addNode'];
		$form['rows']['addNode'];
		$form['rows']->getComponent('addNode');
	}

	// CP-20: Form component 'nope-x' does not exist. [orisai.nette.forms.noSuchComponent] — getComponent()
	// and offsetGet() are the same operation (ComponentModel\ArrayAccess::offsetGet delegates), so
	// the joined name reports identically through the method spelling
	public function cp20(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form->getComponent('nope-x');
	}

	// CP-21: no error — getComponent($name, false) is the no-throw existence check and is left alone
	// on a path exactly as on a single name; so is an isset() guard over the joined spelling
	public function cp21(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');
		$form->getComponent('nope-x', false);
		isset($form['nope-x']);
		$form['nope-x'] ?? null;
	}

	// CP-22: no error — a path under a MAYBE-present leaf still names a real child; presence is a
	// different axis from absence and only the DEFINITE-existence consumer asks about it
	public function cp22(bool $c): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		if ($c) {
			$outer->addText('a');
		}

		$form['outer-a'];
	}

	// CP-23: no error — a value-less control (a submit button) reached through a path segment: it
	// contributes no slot at all, only a component type, and the component side must not read that
	// as absent
	public function cp23(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addSubmit('save', 'Save');
		$form['outer-save'];
	}

	// CP-24: Form value 'outer-a' does not exist. [orisai.nette.forms.noSuchComponent] — the separator carries
	// no meaning at all on the VALUES axis: $values is a data hash keyed by one component's own name,
	// not a component tree, so the very spelling the line above resolves as a nested COMPONENT is
	// looked up literally here and found absent. A values key can never itself contain a separator
	// either — every key is a name addComponent() accepted, and NameRegexp forbids '-' — so this is
	// the only way the two axes can be told apart in one fixture.
	public function cp24(): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addText('a');

		$form['outer-a'];
		$form->getValues()['outer-a'];
	}

}
