<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use DateTimeImmutable;

final class Asymmetry
{

	// G11-text-write: no error for any of '', null, non-empty, native string (scalar|Stringable|null) regardless of setNullable/setRequired
	public function g11_text_write(string $native): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('v');
		$form['a']->setValue($native);
	}

	// G11-int-write: no error for '', null, '5', 5 (scalar accepted)
	public function g11_int_write(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('5');
		$form['a']->setValue(5);
	}

	// G11-float-write: no error for '', null, '1.5', 1.5 (scalar accepted)
	public function g11_float_write(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('1.5');
		$form['a']->setValue(1.5);
	}

	// G11-cb-write: no error for '', null, '1', true (scalar|null accepted)
	public function g11_cb_write(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('1');
		$form['a']->setValue(true);
	}

	// G11-sel-write: no error for '', null, 'opt', 99 (string|int|null accepted; invalid key not a type error)
	public function g11_sel_write(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('opt');
		$form['a']->setValue(99);
	}

	// G11-hid-write: no error for '', null, non-empty, native string (scalar|Stringable|BackedEnum|null)
	public function g11_hid_write(string $native): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('v');
		$form['a']->setValue($native);
	}

	// G11-date-write: no error for '', null, '2024-01-01', native DateTimeImmutable (DateTimeInterface|string|int|null)
	public function g11_date_write(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('2024-01-01');
		$form['a']->setValue(new DateTimeImmutable());
	}

	// G11-col-write: no error for '', null, '#fff', '#ffffff' (string|null accepted)
	public function g11_col_write(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		$form['a']->setValue('');
		$form['a']->setValue(null);
		$form['a']->setValue('#fff');
		$form['a']->setValue('#ffffff');
	}

	// G11-noop-required: no error; identical accepted-set with and without setRequired() (same value accepted both ways)
	public function g11_noop_required(): void
	{
		$without = new ApplicationForm();
		$without->addText('a');
		$without['a']->setValue('x');

		$with = new ApplicationForm();
		$with->addText('a')->setRequired();
		$with['a']->setValue('x');
	}

	// G11-noop-nullable: no error; identical accepted-set with and without setNullable() (write side unchanged)
	public function g11_noop_nullable(): void
	{
		$without = new ApplicationForm();
		$without->addText('a');
		$without['a']->setValue('x');

		$with = new ApplicationForm();
		$with->addText('a')->setNullable();
		$with['a']->setValue('x');
	}

}
