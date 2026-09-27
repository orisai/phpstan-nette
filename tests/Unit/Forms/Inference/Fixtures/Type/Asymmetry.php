<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use DateTimeImmutable;
use Nette\Forms\Controls\DateTimeControl;
use function PHPStan\Testing\assertType;

final class Asymmetry
{

	public function g11_text_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue('');
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue(null);
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue('v');
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_04(string $native): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue($native);
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_05(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setRequired();
		$form['a']->setValue('');
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_06(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setRequired();
		$form['a']->setValue(null);
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_07(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setRequired();
		$form['a']->setValue('v');
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_08(string $native): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setRequired();
		$form['a']->setValue($native);
		assertType('string', $form['a']->getValue());
	}

	public function g11_text_09(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable();
		$form['a']->setValue('');
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_text_10(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable();
		$form['a']->setValue(null);
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_text_11(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable();
		$form['a']->setValue('v');
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_text_12(string $native): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable();
		$form['a']->setValue($native);
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_text_13(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_text_14(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_text_15(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable()->setRequired();
		$form['a']->setValue('v');
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_text_16(string $native): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable()->setRequired();
		$form['a']->setValue($native);
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_int_01(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		$form['a']->setValue('');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_02(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		$form['a']->setValue(null);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_03(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		$form['a']->setValue('5');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_04(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		$form['a']->setValue(5);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_05(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setRequired();
		$form['a']->setValue('');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_06(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setRequired();
		$form['a']->setValue(null);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_07(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setRequired();
		$form['a']->setValue('5');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_08(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setRequired();
		$form['a']->setValue(5);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_09(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable();
		$form['a']->setValue('');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_10(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable();
		$form['a']->setValue(null);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_11(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable();
		$form['a']->setValue('5');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_12(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable();
		$form['a']->setValue(5);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_13(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_14(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_15(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable()->setRequired();
		$form['a']->setValue('5');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_int_16(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setNullable()->setRequired();
		$form['a']->setValue(5);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_float_01(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a');
		$form['a']->setValue('');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_02(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a');
		$form['a']->setValue(null);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_03(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a');
		$form['a']->setValue('1.5');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_04(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a');
		$form['a']->setValue(1.5);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_05(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setRequired();
		$form['a']->setValue('');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_06(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setRequired();
		$form['a']->setValue(null);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_07(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setRequired();
		$form['a']->setValue('1.5');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_08(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setRequired();
		$form['a']->setValue(1.5);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_09(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable();
		$form['a']->setValue('');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_10(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable();
		$form['a']->setValue(null);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_11(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable();
		$form['a']->setValue('1.5');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_12(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable();
		$form['a']->setValue(1.5);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_13(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_14(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_15(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable()->setRequired();
		$form['a']->setValue('1.5');
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_float_16(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a')->setNullable()->setRequired();
		$form['a']->setValue(1.5);
		assertType('float|null', $form['a']->getValue());
	}

	public function g11_cb_01(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		$form['a']->setValue('');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_02(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		$form['a']->setValue(null);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_03(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		$form['a']->setValue('1');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_04(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		$form['a']->setValue(true);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_05(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setRequired();
		$form['a']->setValue('');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_06(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setRequired();
		$form['a']->setValue(null);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_07(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setRequired();
		$form['a']->setValue('1');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_08(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setRequired();
		$form['a']->setValue(true);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_09(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable();
		$form['a']->setValue('');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_10(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable();
		$form['a']->setValue(null);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_11(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable();
		$form['a']->setValue('1');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_12(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable();
		$form['a']->setValue(true);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_13(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_14(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_15(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable()->setRequired();
		$form['a']->setValue('1');
		assertType('bool', $form['a']->getValue());
	}

	public function g11_cb_16(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a')->setNullable()->setRequired();
		$form['a']->setValue(true);
		assertType('bool', $form['a']->getValue());
	}

	public function g11_sel_01(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		$form['a']->setValue('');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_02(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		$form['a']->setValue(null);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_03(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		$form['a']->setValue('opt');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_04(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		$form['a']->setValue(99);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_05(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setRequired();
		$form['a']->setValue('');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_06(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setRequired();
		$form['a']->setValue(null);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_07(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setRequired();
		$form['a']->setValue('opt');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_08(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setRequired();
		$form['a']->setValue(99);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_09(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable();
		$form['a']->setValue('');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_10(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable();
		$form['a']->setValue(null);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_11(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable();
		$form['a']->setValue('opt');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_12(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable();
		$form['a']->setValue(99);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_13(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_14(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_15(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable()->setRequired();
		$form['a']->setValue('opt');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_sel_16(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setNullable()->setRequired();
		$form['a']->setValue(99);
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_hid_01(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		$form['a']->setValue('');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_02(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		$form['a']->setValue(null);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_03(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		$form['a']->setValue('v');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_04(string $native): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		$form['a']->setValue($native);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_05(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setRequired();
		$form['a']->setValue('');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_06(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setRequired();
		$form['a']->setValue(null);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_07(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setRequired();
		$form['a']->setValue('v');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_08(string $native): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setRequired();
		$form['a']->setValue($native);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_09(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable();
		$form['a']->setValue('');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_10(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable();
		$form['a']->setValue(null);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_11(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable();
		$form['a']->setValue('v');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_12(string $native): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable();
		$form['a']->setValue($native);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_13(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_14(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_15(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable()->setRequired();
		$form['a']->setValue('v');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_hid_16(string $native): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a')->setNullable()->setRequired();
		$form['a']->setValue($native);
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_date_01(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		$form['a']->setValue('');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_02(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		$form['a']->setValue(null);
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_03(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		$form['a']->setValue('2024-01-01');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_04(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		$form['a']->setValue(new DateTimeImmutable());
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_05(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setRequired();
		$form['a']->setValue('');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_06(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setRequired();
		$form['a']->setValue(null);
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_07(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setRequired();
		$form['a']->setValue('2024-01-01');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_08(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setRequired();
		$form['a']->setValue(new DateTimeImmutable());
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_09(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable();
		$form['a']->setValue('');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_10(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable();
		$form['a']->setValue(null);
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_11(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable();
		$form['a']->setValue('2024-01-01');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_12(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable();
		$form['a']->setValue(new DateTimeImmutable());
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_13(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_14(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_15(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable()->setRequired();
		$form['a']->setValue('2024-01-01');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_16(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setNullable()->setRequired();
		$form['a']->setValue(new DateTimeImmutable());
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_fmt_01(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat(DateTimeControl::FormatObject);
		$form['a']->setValue('2024-01-01');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g11_date_fmt_02(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat(DateTimeControl::FormatTimestamp);
		$form['a']->setValue(123);
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_date_fmt_03(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat('Y-m-d');
		$form['a']->setValue('2024-01-01');
		assertType('string|null', $form['a']->getValue());
	}

	public function g11_col_01(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		$form['a']->setValue('');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_02(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		$form['a']->setValue(null);
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_03(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		$form['a']->setValue('#fff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_04(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		$form['a']->setValue('#ffffff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_05(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setRequired();
		$form['a']->setValue('');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_06(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setRequired();
		$form['a']->setValue(null);
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_07(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setRequired();
		$form['a']->setValue('#fff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_08(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setRequired();
		$form['a']->setValue('#ffffff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_09(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable();
		$form['a']->setValue('');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_10(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable();
		$form['a']->setValue(null);
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_11(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable();
		$form['a']->setValue('#fff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_12(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable();
		$form['a']->setValue('#ffffff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_13(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable()->setRequired();
		$form['a']->setValue('');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_14(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable()->setRequired();
		$form['a']->setValue(null);
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_15(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable()->setRequired();
		$form['a']->setValue('#fff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_col_16(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a')->setNullable()->setRequired();
		$form['a']->setValue('#ffffff');
		assertType('string', $form['a']->getValue());
	}

	public function g11_req_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('string', $form['a']->getValue());
	}

	public function g11_req_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setRequired();
		assertType('string', $form['a']->getValue());
	}

	public function g11_req_03(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_req_04(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a')->setRequired();
		assertType('int|null', $form['a']->getValue());
	}

	public function g11_req_05(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_req_06(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a')->setRequired();
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g11_req_07(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable();
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g11_req_08(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable()->setRequired();
		assertType('non-empty-string|null', $form['a']->getValue());
	}

}
