<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class MFormSeparateStatementModifiers extends BaseFormControl
{

	protected function createComponentSeparate(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('gate');

		$rule = $form->addText('rule');
		$rule->addRule(Form::Integer);

		$nullable = $form->addText('nullable');
		$nullable->setNullable();

		$required = $form->addText('required');
		$required->setRequired();

		$ts = $form->addDate('ts');
		$ts->setFormat(DateTimeControl::FormatTimestamp);

		$cond = $form->addText('cond');
		$cond->addConditionOn($form['gate'], Form::Equal, 1)->addRule(Form::Integer);

		$mixed = $form->addText('mixed');
		$mixed->addConditionOn($form['gate'], Form::Equal, 1)->addRule(Form::Integer);
		$mixed->addRule(Form::Float);

		$viaRulesVar = $form->addText('viaRulesVar');
		$rules = $viaRulesVar->addConditionOn($form['gate'], Form::Equal, 1);
		$rules->addRule(Form::Integer);

		$form->onSuccess[] = function (ApplicationForm $form): void {
			// => Nette\Utils\ArrayHash{gate: string, rule: ''|int, nullable: non-empty-string|null, required: non-empty-string, ts: int|null, cond: int|string, mixed: ''|float|int, viaRulesVar: int|string}
			dumpType($form->getValues());
		};

		return $form;
	}

}
