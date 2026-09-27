<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Controls\DateTimeControl;
use function PHPStan\Testing\assertType;

final class GetValue
{

	public function g2_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('string', $form['a']->getValue());
	}

	public function g2_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable();
		assertType('non-empty-string|null', $form['a']->getValue());
	}

	public function g2_03(): void
	{
		$form = new ApplicationForm();
		$form->addPassword('a');
		assertType('string', $form['a']->getValue());
	}

	public function g2_04(): void
	{
		$form = new ApplicationForm();
		$form->addTextArea('a');
		assertType('string', $form['a']->getValue());
	}

	public function g2_05(): void
	{
		$form = new ApplicationForm();
		$form->addEmail('a');
		assertType('string', $form['a']->getValue());
	}

	public function g2_06(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		assertType('string', $form['a']->getValue());
	}

	public function g2_07(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		assertType('int|null', $form['a']->getValue());
	}

	public function g2_08(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a');
		assertType('float|null', $form['a']->getValue());
	}

	public function g2_09(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		assertType('bool', $form['a']->getValue());
	}

	public function g2_10(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		assertType('string|null', $form['a']->getValue());
	}

	public function g2_11(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g2_12(): void
	{
		$form = new ApplicationForm();
		$form->addRadioList('a');
		assertType('int|string|null', $form['a']->getValue());
	}

	public function g2_13(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('a');
		assertType('list<int|string>', $form['a']->getValue());
	}

	public function g2_14(): void
	{
		$form = new ApplicationForm();
		$form->addCheckboxList('a');
		assertType('list<int|string>', $form['a']->getValue());
	}

	public function g2_15(): void
	{
		$form = new ApplicationForm();
		$form->addUpload('a');
		assertType('Nette\Http\FileUpload|null', $form['a']->getValue());
	}

	public function g2_16(): void
	{
		$form = new ApplicationForm();
		$form->addMultiUpload('a');
		assertType('list<Nette\Http\FileUpload>|null', $form['a']->getValue());
	}

	public function g2_17(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g2_18(): void
	{
		$form = new ApplicationForm();
		$form->addTime('a');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g2_19(): void
	{
		$form = new ApplicationForm();
		$form->addDateTime('a');
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g2_20(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat(DateTimeControl::FormatTimestamp);
		assertType('int|null', $form['a']->getValue());
	}

	public function g2_21(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat(DateTimeControl::FormatObject);
		assertType('DateTimeImmutable|null', $form['a']->getValue());
	}

	public function g2_22(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat('Y-m-d');
		assertType('string|null', $form['a']->getValue());
	}

	public function g2_23(string $dynamic): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat($dynamic);
		assertType('mixed', $form['a']->getValue());
	}

}
