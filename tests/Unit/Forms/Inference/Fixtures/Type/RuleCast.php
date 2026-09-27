<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Form;
use function PHPStan\Testing\assertType;

final class RuleCast
{

	private bool $flag = false;

	public function integerEqualsAddInteger(): void
	{
		$ruled = new ApplicationForm();
		$ruled->addText('i')->addRule(Form::Integer);

		$native = new ApplicationForm();
		$native->addInteger('i');

		assertType('\'\'|int', $ruled['i']->getValue());
		assertType('int|null', $native['i']->getValue());
	}

	public function integerEqualsAddIntegerViaGetValues(): void
	{
		$ruled = new ApplicationForm();
		$ruled->addText('i')->addRule(Form::Integer);

		$native = new ApplicationForm();
		$native->addInteger('i');

		assertType('\'\'|int', $ruled->getValues()->i);
		assertType('int|null', $native->getValues()->i);
	}

	public function integerViaLegacyAlias(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->addRule(Form::INTEGER);
		assertType('\'\'|int', $form['i']->getValue());
	}

	public function floatRule(): void
	{
		$form = new ApplicationForm();
		$form->addText('f')->addRule(Form::Float);
		assertType('\'\'|float', $form['f']->getValue());
	}

	public function conditionalRuleWidens(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$form->addText('c')->addConditionOn($form['x'], Form::Equal, 1)->addRule(Form::Integer);
		assertType('int|string', $form['c']->getValue());
	}

	public function addConditionRuleWidens(): void
	{
		$form = new ApplicationForm();
		$form->addText('c2')->addCondition(Form::Filled)->addRule(Form::Float);
		assertType('float|string', $form['c2']->getValue());
	}

	public function multipleConditionalCastsUnionAllReadTypes(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$form->addText('c')
			->addConditionOn($form['x'], Form::Equal, 1)->addRule(Form::Integer)->endCondition()
			->addConditionOn($form['x'], Form::Equal, 2)->addRule(Form::Float);
		assertType('float|int|string', $form['c']->getValue());
	}

	public function conditionalCastComposesWithNullable(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$form->addText('c')->setNullable()->addConditionOn($form['x'], Form::Equal, 1)->addRule(Form::Integer);
		assertType('int|non-empty-string|null', $form['c']->getValue());
	}

	public function endConditionReopensUnconditional(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$form->addText('c')->addConditionOn($form['x'], Form::Equal, 1)->endCondition()->addRule(Form::Float);
		assertType('\'\'|float', $form['c']->getValue());
	}

	public function nestedConditionsClosedToRootThenRule(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$form->addText('c')
			->addConditionOn($form['x'], Form::Equal, 1)
			->addConditionOn($form['x'], Form::Equal, 2)
			->addRule(Form::Integer)
			->endCondition()
			->endCondition()
			->addRule(Form::Float);
		assertType('\'\'|float|int', $form['c']->getValue());
	}

	public function conditionalCastWidensInNestedContainer(): void
	{
		$form = new ApplicationForm();
		$age = $form->addContainer('age');
		$age->addText('from')->addCondition(Form::Filled)->addRule(Form::Integer);
		assertType('int|string', $form->getValues()->age->from);
	}

	public function emailRuleDoesNotNarrow(): void
	{
		$form = new ApplicationForm();
		$form->addText('e')->addRule(Form::Email);
		assertType('string', $form['e']->getValue());
	}

	/** @param int|string $dynamicRule */
	public function dynamicRuleDoesNotNarrow($dynamicRule): void
	{
		$form = new ApplicationForm();
		$form->addText('d')->addRule($dynamicRule);
		assertType('string', $form['d']->getValue());
	}

	public function nullableComposesWithRule(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->setNullable()->addRule(Form::Integer);
		assertType('int|null', $form['i']->getValue());
	}

	public function nullableRuledEqualsAddInteger(): void
	{
		$nullableRuled = new ApplicationForm();
		$nullableRuled->addText('i')->setNullable()->addRule(Form::Integer);

		$native = new ApplicationForm();
		$native->addInteger('i');

		assertType('int|null', $nullableRuled['i']->getValue());
		assertType('int|null', $native['i']->getValue());
	}

	public function requiredRuledDropsEmpty(): void
	{
		$form = new ApplicationForm();
		$form->addText('i')->setRequired()->addRule(Form::Integer);
		assertType('\'\'|int', $form['i']->getValue());
	}

	public function separateStatementRuleEqualsInline(): void
	{
		$form = new ApplicationForm();
		$d = $form->addText('d');
		$d->addRule(Form::Integer);
		assertType('\'\'|int', $form['d']->getValue());
	}

	public function separateStatementConditionalRuleWidens(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$c = $form->addText('c');
		$c->addConditionOn($form['x'], Form::Equal, 1)->addRule(Form::Integer);
		assertType('int|string', $form['c']->getValue());
	}

	public function separateStatementConditionalThenUnconditionalRule(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$d = $form->addText('d');
		$d->addConditionOn($form['x'], Form::Equal, 1)->addRule(Form::Integer);
		$d->addRule(Form::Float);
		assertType('\'\'|float|int', $form['d']->getValue());
	}

	public function rulesVariableConditionalRuleWidens(): void
	{
		$form = new ApplicationForm();
		$form->addText('x');
		$c = $form->addText('c');
		$rules = $c->addConditionOn($form['x'], Form::Equal, 1);
		$rules->addRule(Form::Integer);
		assertType('int|string', $form['c']->getValue());
	}

	public function rulesVariableGetRulesUnconditional(): void
	{
		$form = new ApplicationForm();
		$c = $form->addText('i');
		$rules = $c->getRules();
		$rules->addRule(Form::Integer);
		assertType('\'\'|int', $form['i']->getValue());
	}

	public function separateStatementNullableEqualsInline(): void
	{
		$form = new ApplicationForm();
		$n = $form->addText('n');
		$n->setNullable();

		$inline = new ApplicationForm();
		$inline->addText('n')->setNullable();

		assertType('non-empty-string|null', $form['n']->getValue());
		assertType('non-empty-string|null', $inline['n']->getValue());
	}

	public function separateStatementRequiredEqualsInline(): void
	{
		$form = new ApplicationForm();
		$r = $form->addText('r');
		$r->setRequired();

		$inline = new ApplicationForm();
		$inline->addText('r')->setRequired();

		assertType('string', $form['r']->getValue());
		assertType('string', $inline['r']->getValue());
	}

	public function separateStatementFormatEqualsInline(): void
	{
		$form = new ApplicationForm();
		$ts = $form->addDate('ts');
		$ts->setFormat(DateTimeControl::FormatTimestamp);

		$inline = new ApplicationForm();
		$inline->addDate('ts')->setFormat(DateTimeControl::FormatTimestamp);

		assertType('int|null', $form['ts']->getValue());
		assertType('int|null', $inline['ts']->getValue());
	}

	public function separateStatementSetItemsEqualsInline(): void
	{
		$form = new ApplicationForm();
		$s = $form->addSelect('s');
		$s->setItems(['a' => 'A', 'b' => 'B']);

		$inline = new ApplicationForm();
		$inline->addSelect('s')->setItems(['a' => 'A', 'b' => 'B']);

		assertType('\'a\'|\'b\'|null', $form['s']->getValue());
		assertType('\'a\'|\'b\'|null', $inline['s']->getValue());
	}

	public function separateStatementRuleAfterReassignAttributesToNewControl(): void
	{
		$form = new ApplicationForm();
		$c = $form->addText('c');
		$c = $form->addText('d');
		$c->addRule(Form::Integer);
		assertType('string', $form['c']->getValue());
		assertType('\'\'|int', $form['d']->getValue());
	}

	public function separateStatementControlEscapesToHelperOpens(): void
	{
		$form = new ApplicationForm();
		$c = $form->addText('c');
		$this->configure($c);
		assertType('mixed', $form['c']->getValue());
	}

	public function conditionalBranchRuleWidens(): void
	{
		$form = new ApplicationForm();
		$c = $form->addText('c');
		if ($this->flag) {
			$c->addRule(Form::Integer);
		}

		assertType('int|string', $form['c']->getValue());
	}

	public function conditionalBranchNullableMaybeNull(): void
	{
		$form = new ApplicationForm();
		$c = $form->addText('c');
		if ($this->flag) {
			$c->setNullable();
		}

		assertType('string|null', $form['c']->getValue());
	}

	public function conditionalBranchNonCastRuleStaysString(): void
	{
		$form = new ApplicationForm();
		$c = $form->addText('c');
		if ($this->flag) {
			$c->setRequired()->addRule(Form::Email);
		}

		assertType('string', $form['c']->getValue());
	}

	public function conditionalBranchFormatAppliesInBranchAndJoins(): void
	{
		$form = new ApplicationForm();
		$c = $form->addDate('c');
		if ($this->flag) {
			$c->setFormat(DateTimeControl::FormatTimestamp);
		}

		assertType('DateTimeImmutable|int|null', $form['c']->getValue());
	}

	public function controlAliasRefinesSameSlot(): void
	{
		$form = new ApplicationForm();
		$c = $form->addText('c');
		$d = $c;
		$d->addRule(Form::Integer);
		assertType('\'\'|int', $form['c']->getValue());
	}

	public function conditionalBranchAliasFormatAppliesInBranchAndJoins(): void
	{
		$form = new ApplicationForm();
		$c = $form->addDate('c');
		if ($this->flag) {
			$d = $c;
			$d->setFormat(DateTimeControl::FormatTimestamp);
		}

		assertType('DateTimeImmutable|int|null', $form['c']->getValue());
	}

	private function configure(TextInput $control): void
	{
		$control->setNullable();
	}

}
