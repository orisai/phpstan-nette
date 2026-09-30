<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Controls\DateTimeControl;

final class UnknownAccess
{

	// G12-01: Form value 'nope' may not exist; the form shape is open. [orisai.nette.forms.unknownAccess] (tip: Form shape opened by: dynamic_name)
	public function g12_01(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addText($name);
		$form->getValues()->nope;
	}

	// G12-02: Form value 'nope' may not exist; the form shape is open. [orisai.nette.forms.unknownAccess] (tip: Form shape opened by: extension_method)
	public function g12_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addSomethingUnknown();
		$form->getValues()->nope;
	}

	// G12-03: Form value 'nope' may not exist; the form shape is open. [orisai.nette.forms.unknownAccess] (tip: Form shape opened by: dynamic_name,extension_method)
	public function g12_03(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addText($name);
		$form->addSomethingUnknown();
		$form->getValues()->nope;
	}

	// G12-04: no error — setFormat(FormatTimestamp) yields a precise int|null, so 'a' is a known field.
	public function g12_04(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat(DateTimeControl::FormatTimestamp);
		$form->getValues()->a;
	}

	// G12-05: no inference error (baseline-suppressed IComponent path; factory not followed)
	public function g12_05(): void
	{
		$form = $this->buildForm();
		$form->getValues()->whatever;
	}

	// G12-06: Form value 'nope' does not exist. [orisai.nette.forms.noSuchComponent]
	public function g12_06(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->getValues()->nope;
	}

	// G12-07: Form component 'nope' does not exist. [orisai.nette.forms.noSuchComponent]
	public function g12_07(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['nope'];
	}

	// G12-08: no error. A form-mutating callable whose invocation timing PHPStan answers MAYBE for
	// may have added the accessed name, so callback_timing is a lost-field reason and the access is
	// suppressed. The rule derives that set from UnknownReason::LOST_FIELD_UNKNOWN_REASONS rather
	// than re-spelling it, which is what keeps this row true when a reason is added to the constant.
	public function g12_08(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$this->maybeInvoke(static function () use ($form): void {
			$form->addText('fromCallback');
		});
		$form->getValues()->nope;
	}

	private function maybeInvoke(callable $mutator): void
	{
		$mutator();
	}

	private function buildForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('a');

		return $form;
	}

}
