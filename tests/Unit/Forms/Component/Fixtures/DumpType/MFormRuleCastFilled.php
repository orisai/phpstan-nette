<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class MFormRuleCastFilled extends BaseFormControl
{

	protected function createComponentRuled(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('bare')->addRule(Form::Integer);
		$form->addText('req')->setRequired()->addRule(Form::Integer);
		$form->addText('cond')->addCondition(Form::Filled)->addRule(Form::Integer);

		$form->onSuccess[] = function (ApplicationForm $form): void {
			dumpType($form->getValues()); // => Nette\Utils\ArrayHash{bare: ''|int, req: int, cond: int|string}
		};

		return $form;
	}

	protected function createComponentNative(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addInteger('bare');
		$form->addInteger('req')->setRequired();

		$form->onSuccess[] = function (ApplicationForm $form): void {
			dumpType($form->getValues()); // => Nette\Utils\ArrayHash{bare: int|null, req: int}
		};

		return $form;
	}

}
