<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Forms\Controls\TextInput;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

/**
 * offsetExists() is getComponent($name, false) !== null, so an existence check RUNS the lazy
 * create-and-attach block. A factory therefore makes the check TRUE, and the interesting
 * always-false case is a name that is neither held nor buildable.
 */
final class ConstantExistenceCheck
{

	// CE-01: always exists — a child the closed shape holds
	public function ce01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		isset($form['a']);
	}

	// CE-02: never exists — closed shape, no such child, and no factory that could build one
	public function ce02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		isset($form['nope']);
	}

	// CE-03: always exists — the check RUNS createComponentSub() and attaches what it returns; this
	// is the case that reads backwards, since the child is absent when the check starts
	public function ce03(): void
	{
		$form = new FactoryDeclaringForm();
		$form->addText('a');
		isset($form['sub']);
	}

	// CE-04: never exists — createComponent() requires ucfirst($name) !== $name, so a capitalised
	// name can have no factory at all and the check has nothing to build
	public function ce04(): void
	{
		$form = new FactoryDeclaringForm();
		$form->addText('a');
		isset($form['Sub']);
	}

	// CE-05: no error — an open shape proves nothing about a name it does not hold
	public function ce05(string $dynamic): void
	{
		$form = new ApplicationForm();
		$form->addText($dynamic);
		isset($form['nope']);
	}

	// CE-06: no error — added on one arm only, so the presence is Maybe on both sides
	public function ce06(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$form->addText('maybe');
		}

		isset($form['maybe']);
	}

	// CE-07: no error — a '-'-joined path is resolved hop by hop; the presence this rule has
	// describes one child
	public function ce07(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('outer');
		isset($form['outer-nope']);
	}

	// CE-08: never exists — offsetExists() spelled out is the same check
	public function ce08(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->offsetExists('nope');
	}

	// CE-09: always exists — the same, on a child that is there
	public function ce09(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->offsetExists('a');
	}

	// CE-10: no error — a name this cannot read
	public function ce10(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		isset($form[$name]);
	}

	// CE-11: no error — a VALUES property is not a component existence check
	public function ce11(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$v = $form->getValues();
		isset($v->nope);
	}

	// CE-12: no error — empty() is isset() plus a truthiness test, so its answer is not the check's
	public function ce12(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		empty($form['nope']);
	}

	// CE-13: no error — null-coalesce is left alone for the same reason
	public function ce13(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['nope'] ?? null;
	}

	// CE-14: never exists — a nested container's own closed shape answers about its own children
	public function ce14(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('outer');
		isset($form['outer']['nope']);
	}

	// CE-15: no error — the same container, but the add chained onto it escapes the reference, so
	// its shape opens and nothing inside it is provably absent
	public function ce15(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('escaped')->addText('inner');
		isset($form['escaped']['nope']);
	}

	// CE-16: always exists — every hop of the chain is definite
	public function ce16(): void
	{
		$form = new ApplicationForm();
		$inner = $form->addContainer('outer');
		$inner->addText('inner');
		isset($form['outer']['inner']);
	}

	// CE-17: no error — the corpus shape. The inner name is definite INSIDE its container, but the
	// container itself is only there on one arm, so the check can still answer false and the guard
	// is not redundant
	public function ce17(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$inner = $form->addContainer('sometimes');
			$inner->addText('inner');
		}

		isset($form['sometimes']['inner']);
	}

	// CE-18: never exists — one provably absent hop settles the whole chain, and it is the hop worth
	// naming: nothing above it is ever reached
	public function ce18(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		isset($form['nosuch']['inner']);
	}

}

final class FactoryDeclaringForm extends ApplicationForm
{

	protected function createComponentSub(): TextInput
	{
		return new TextInput('Sub');
	}

}
