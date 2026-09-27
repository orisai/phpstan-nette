<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function array_combine;
use function PHPStan\Testing\assertType;

final class ChoiceItems
{

	private const STATUSES = ['draft' => 'Draft', 'live' => 'Live'];

	private const PRIORITIES = [1 => 'Low', 2 => 'High'];

	/** @var array<string, string> */
	private array $dynamic = [];

	public function ci_select_literal_assoc(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B']);
		assertType("'a'|'b'|null", $form['s']->getValue());
	}

	public function ci_select_literal_assoc_required(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B'])->setRequired();
		assertType("'a'|'b'|null", $form['s']->getValue());
	}

	public function ci_select_literal_list(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['x', 'y', 'z']);
		assertType('0|1|2|null', $form['s']->getValue());
	}

	public function ci_select_int_keys(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', [10 => 'Ten', 20 => 'Twenty']);
		assertType('10|20|null', $form['s']->getValue());
	}

	public function ci_radio_literal_assoc(): void
	{
		$form = new ApplicationForm();
		$form->addRadioList('r', 'l', ['yes' => 'Yes', 'no' => 'No']);
		assertType("'no'|'yes'|null", $form['r']->getValue());
	}

	public function ci_select_setitems(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s')->setItems(['a' => 'A', 'b' => 'B']);
		assertType("'a'|'b'|null", $form['s']->getValue());
	}

	public function ci_select_const(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', self::STATUSES);
		assertType("'draft'|'live'|null", $form['s']->getValue());
	}

	public function ci_select_const_setitems(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s')->setItems(self::STATUSES);
		assertType("'draft'|'live'|null", $form['s']->getValue());
	}

	public function ci_select_const_int_keys(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', self::PRIORITIES);
		assertType('1|2|null', $form['s']->getValue());
	}

	public function ci_multiselect_literal_assoc(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('m', 'l', ['x' => 'X', 'y' => 'Y']);
		assertType("list<'x'|'y'>", $form['m']->getValue());
	}

	public function ci_checkboxlist_literal_assoc(): void
	{
		$form = new ApplicationForm();
		$form->addCheckboxList('c', 'l', ['x' => 'X', 'y' => 'Y']);
		assertType("list<'x'|'y'>", $form['c']->getValue());
	}

	public function ci_multiselect_setitems_const(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('m')->setItems(self::STATUSES);
		assertType("list<'draft'|'live'>", $form['m']->getValue());
	}

	public function ci_select_usekeys_false_list(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s')->setItems(['x', 'y'], false);
		assertType("'x'|'y'|null", $form['s']->getValue());
	}

	public function ci_select_usekeys_false_assoc(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s')->setItems(['k1' => 'x', 'k2' => 'y'], false);
		assertType("'x'|'y'|null", $form['s']->getValue());
	}

	public function ci_select_dynamic_variable(): void
	{
		$items = $this->dynamic;
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', $items);
		assertType('int|string|null', $form['s']->getValue());
	}

	public function ci_select_dynamic_property(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', $this->dynamic);
		assertType('int|string|null', $form['s']->getValue());
	}

	public function ci_select_dynamic_array_combine(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', array_combine(['a'], ['A']));
		assertType('int|string|null', $form['s']->getValue());
	}

	public function ci_select_setitems_dynamic(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s')->setItems($this->dynamic);
		assertType('int|string|null', $form['s']->getValue());
	}

	public function ci_select_check_default_value_false(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B'])->checkDefaultValue(false);
		assertType("'a'|'b'|null", $form['s']->getValue());
	}

	public function ci_select_setprompt(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B'])->setPrompt('—');
		assertType("'a'|'b'|null", $form['s']->getValue());
	}

	public function ci_select_optgroup_widens(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['Group' => ['a' => 'A', 'b' => 'B']]);
		assertType('int|string|null', $form['s']->getValue());
	}

	public function ci_select_double_setitems_last_wins(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s')->setItems(['a' => 'A'])->setItems(['b' => 'B', 'c' => 'C']);
		assertType("'b'|'c'|null", $form['s']->getValue());
	}

	public function ci_select_double_setitems_dynamic_last_widens(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s')->setItems(['a' => 'A'])->setItems($this->dynamic);
		assertType('int|string|null', $form['s']->getValue());
	}

	public function ci_select_empty_items_widens(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', []);
		assertType('int|string|null', $form['s']->getValue());
	}

}
