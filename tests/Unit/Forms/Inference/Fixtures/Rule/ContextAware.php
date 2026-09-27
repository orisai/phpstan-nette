<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function OriPhpstan\Nette\Forms\Testing\dumpComponent;
use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class ContextAware extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name')->setRequired();
		$form->addInteger('age');

		return $form;
	}

	public function go(): void
	{
		if ($this['form']->isValid()) {
			dumpFormValues($this['form']);
			dumpComponent($this['form']);
		}

		dumpFormValues($this['form']);
		dumpComponent($this['form']);
	}

}
