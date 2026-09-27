<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\ComponentModel\IComponent;
use Nette\Forms\Container;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Forms\Form;
use Nette\Forms\Controls\TextArea;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\Gap5ParentControl;

final class InvalidCode
{

	private bool $flag = false;

	public function staticOnlyMissingFieldStillReported(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');

		$form->getValues()->doesNotExist;
	}

	public function multiReturnMissingOnAllPathsStillReported(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('common');

		if ($this->flag) {
			$form->addText('earlyOnly');

			return $form;
		}

		$form->addText('lateOnly');

		$form->getValues()->neverAdded;
		$form->getValues()->earlyOnly;
		$form->getValues()->lateOnly;

		return $form;
	}

	public function multiReturnPresentOnAllPathsVarTagMismatchReported(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('common');

		if ($this->flag) {
			$form->addText('earlyOnly');

			return $form;
		}

		$form->addText('lateOnly');

		/** @var TextArea $c */
		$c = $form['common'];
		$c->setValue('x');

		return $form;
	}

	public function staticOnlyMissingComponentStillReported(): IComponent
	{
		$form = new ApplicationForm();
		$form->addText('name');

		return $form['doesNotExist'];
	}

	public function varTagTypeMismatchReported(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');

		/** @var TextArea $c */
		$c = $form['name'];
		$c->setValue('x');
	}

	public function preciseRemovalViaGetComponentStillReported(): void
	{
		$form = new ApplicationForm();
		$form->addText('keep');
		$form->addText('gone');
		$form->removeComponent($form->getComponent('gone'));

		$form->getValues()->gone;
	}

	public function addComponentFoldedChildVarTagMismatchReported(): void
	{
		$form = new ApplicationForm();
		$inner = new Container();
		$inner->addText('innerField');
		$form->addComponent($inner, 'sub');

		/** @var TextArea $c */
		$c = $form['sub']['innerField'];
		$c->setValue('x');
	}

	public function containerRefReassignMissingFieldStillReported(): IComponent
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('area');
		$c->addCheckbox('flag');
		$c = $c->addText('reused');

		return $form['area']['nope'];
	}

	public function containerRefReassignRetainedFieldVarTagMismatchReported(): void
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('area');
		$c->addCheckbox('flag');
		$c = $c->addText('reused');

		/** @var TextArea $x */
		$x = $form['area']['flag'];
		$x->setValue('x');
	}

}

final class InvalidCodeMappedFormControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->setMappedType(Dto::class);
		$form->addText('name');

		return $form;
	}

	public function readingPropertyNotOnDtoReported(): void
	{
		$this['form']->getValues()->notOnDto;
	}

}

final class InvalidCodeParentChildFormControl extends Gap5ParentControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = parent::createComponentForm();
		$form->addText('fromChild');

		return $form;
	}

	public function neitherParentNorChildReported(): void
	{
		$this['form']->getValues()->neitherParentNorChild;
	}

}

final class InvalidCodeDateFormat
{

	public function timestampFormatValueIsNotDateTime(): void
	{
		$form = new ApplicationForm();
		$form->addDate('ts')->setFormat(DateTimeControl::FormatTimestamp);

		$form['ts']->getValue()->format('Y-m-d');
	}

	public function missingFieldStillReportedWithFormat(): void
	{
		$form = new ApplicationForm();
		$form->addDate('ts')->setFormat(DateTimeControl::FormatTimestamp);

		$form->getValues()->absent;
	}

}

final class InvalidCodeRuleCast
{

	public function compareIntegerRuledToNonNumericAlwaysFalse(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->addRule(Form::Integer);
		if ($form['i']->getValue() === 'notnum') {
			echo 'unreachable';
		}
	}

	public function compareIntegerRuledToNullAlwaysFalse(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->addRule(Form::Integer);
		if ($form['i']->getValue() === null) {
			echo 'unreachable';
		}
	}

	public function compareIntegerRuledToEmptyStringReachable(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->addRule(Form::Integer);
		if ($form['i']->getValue() === '') {
			echo 'reachable';
		}
	}

	public function setValueArrayOnIntegerRuledRejectedLikeAddInteger(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->addRule(Form::Integer);
		$form['i']->setValue([1, 2]);
	}

	public function conditionalIntegerRuleWidenedStringMemberStillMatchesNotnum(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->addCondition(Form::Filled)->addRule(Form::Integer);
		if ($form['i']->getValue() === 'notnum') {
			echo 'reachable';
		}
	}

	public function conditionallyCastFieldRejectedByStringOnlyParam(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->addCondition(Form::Filled)->addRule(Form::Integer);
		$this->stringOnly($form['i']->getValue());
	}

	private function stringOnly(string $value): void
	{
		echo $value;
	}

}

final class InvalidCodeChoiceItems
{

	private const STATUSES = ['draft' => 'Draft', 'live' => 'Live'];

	public function compareNarrowedSelectToNonKeyAlwaysFalse(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B']);
		if ($form['s']->getValue() === 'zzz') {
			echo 'unreachable';
		}
	}

	public function compareNarrowedConstSelectToNonKeyAlwaysFalse(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', self::STATUSES);
		if ($form['s']->getValue() === 'archived') {
			echo 'unreachable';
		}
	}

	public function checkDefaultValueFalseStaysClosedAlwaysFalse(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B'])->checkDefaultValue(false);
		if ($form['s']->getValue() === 'zzz') {
			echo 'unreachable';
		}
	}

}
