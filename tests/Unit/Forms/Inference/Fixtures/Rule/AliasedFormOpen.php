<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class AliasedFormOpen
{

	// AO-01: no error — alias mutation opens the shape, so the dropped field is not reported missing
	public function ao01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$alias = $form;
		$alias->addText('b');
		$form['b'];
	}

	// AO-02: no error — same for a reference alias
	public function ao02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$ref = &$form;
		$ref->addText('b');
		$form['b'];
	}

	// AO-03: Form component 'nope' does not exist. [orisaiNette.forms.noSuchComponent] — read-only alias keeps the shape closed
	public function ao03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$alias = $form;
		$alias->getValues();
		$form['nope'];
	}

	// AO-04: no error — chained-assignment alias mutation opens the shape, so the dropped field is not reported missing
	public function ao04(): void
	{
		$form = $alias = new ApplicationForm();
		$form->addText('a');
		$alias->addText('b');
		$form['b'];
	}

	// AO-05: no error — array-destructuring alias mutation opens the shape, so the dropped field is not reported missing
	public function ao05(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		[$alias] = [$form];
		$alias->addText('b');
		$form['b'];
	}

	// AO-06: no error — list()-destructuring alias mutation opens the shape, so the dropped field is not reported missing
	public function ao06(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		list($alias) = [$form];
		$alias->addText('b');
		$form['b'];
	}

	// AO-07: Form component 'nope' does not exist. [orisaiNette.forms.noSuchComponent] — read-only destructuring alias keeps the shape closed
	public function ao07(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		[$alias] = [$form];
		$alias->getValues();
		$form['nope'];
	}

	// AO-08: no error — an escaping by-ref closure may add fields elsewhere, so the shape is open
	public function ao08(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$build = function () use (&$form): void {
			$form->addText('fromClosure');
		};
		$this->store($build);
		$form['fromClosure'];
	}

	// AO-09: Form component 'nope' does not exist. [orisaiNette.forms.noSuchComponent] — an uninvoked, non-escaping by-ref closure adds nothing and keeps the shape closed
	public function ao09(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$build = function () use (&$form): void {
			$form->addText('nope');
		};
		unset($build);
		$form['nope'];
	}

	// AO-10: no error — an invoked by-ref closure really adds the field
	public function ao10(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$build = function () use (&$form): void {
			$form->addText('viaInvoke');
		};
		$build();
		$form['viaInvoke'];
	}

	private function store(callable $closure): void
	{
	}

}
