<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use DateTimeImmutable;
use stdClass;

final class Write
{

	// G8-01: no error (scalar accepted)
	public function g8_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue('x');
	}

	// G8-02: no error (scalar accepted)
	public function g8_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue(5);
	}

	// G8-03: no error (null accepted)
	public function g8_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue(null);
	}

	// G8-04: Form field 'a' (Nette\Forms\Controls\TextInput) accepts scalar|Stringable|null, array{1, 2} given. [orisai.nette.forms.writeType]
	public function g8_04(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form['a']->setValue([1, 2]);
	}

	// G8-05: no error
	public function g8_05(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		$form['a']->setValue(5);
	}

	// G8-06: no error (scalar accepted)
	public function g8_06(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		$form['a']->setDefaultValue('5');
	}

	// G8-07: no error (scalar accepted)
	public function g8_07(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		$form['a']->setValue(true);
	}

	// G8-08: Form field 'a' (Nette\Forms\Controls\Checkbox) accepts scalar|null, array{'x'} given. [orisai.nette.forms.writeType]
	public function g8_08(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		$form['a']->setValue(['x']);
	}

	// G8-09: no error (int accepted)
	public function g8_09(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		$form['a']->setValue(3);
	}

	// G8-10: Form field 'a' (Nette\Forms\Controls\SelectBox) accepts string|int|BackedEnum|null, float given. [orisai.nette.forms.writeType]
	public function g8_10(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		$form['a']->setValue(1.5);
	}

	// G8-11: no error (iterable accepted)
	public function g8_11(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('a');
		$form['a']->setValue([1, 2]);
	}

	// G8-12: Form field 'a' (Nette\Forms\Controls\MultiSelectBox) accepts iterable<scalar|Stringable|BackedEnum>|scalar|null, stdClass given. [orisai.nette.forms.writeType]
	public function g8_12(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('a');
		$form['a']->setValue(new stdClass());
	}

	// G8-13: no error
	public function g8_13(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		$form['a']->setValue('x');
	}

	// G8-14: no error (DateTimeInterface accepted)
	public function g8_14(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		$form['a']->setValue(new DateTimeImmutable());
	}

	// G8-15: Form field 'a' (Nette\Forms\Controls\DateTimeControl) accepts DateTimeInterface|string|int|null, true given. [orisai.nette.forms.writeType]
	public function g8_15(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		$form['a']->setValue(true);
	}

	// G8-16: no error
	public function g8_16(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		$form['a']->setValue('#fff');
	}

	// G8-17: Form field 'a' (Nette\Forms\Controls\ColorPicker) accepts string|null, int given. [orisai.nette.forms.writeType]
	public function g8_17(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		$form['a']->setValue(5);
	}

	// G8-18: setValue() on upload field 'a' has no effect. [orisai.nette.forms.writeNoEffect]
	public function g8_18(): void
	{
		$form = new ApplicationForm();
		$form->addUpload('a');
		$form['a']->setValue('x');
	}

	// G8-19: setValue() on upload field 'a' has no effect. [orisai.nette.forms.writeNoEffect]
	public function g8_19(): void
	{
		$form = new ApplicationForm();
		$form->addUpload('a');
		$form['a']->setDefaultValue(null);
	}

	// G8-20: no error (setRequired() does NOT change the accepted set)
	public function g8_20(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setRequired();
		$form['a']->setValue('x');
	}

}
