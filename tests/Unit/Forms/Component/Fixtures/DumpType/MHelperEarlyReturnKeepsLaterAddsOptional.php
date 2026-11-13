<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

// A followed helper's bare `return;` is an exit of the helper with the form as it stands there, so
// what the helper adds after it is only MAYBE added — the caller's form has it on one path only.
final class MHelperEarlyReturnKeepsLaterAddsOptional extends Control
{

	private bool $flag = false;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$this->addDetails($form, $this->flag);
		$this->addMore($form);

		return $form;
	}

	private function addDetails(ApplicationForm $form, bool $flag): void
	{
		$form->addText('always');

		if (!$flag) {
			return;
		}

		$form->addText('sometimes');
	}

	private function addMore(ApplicationForm $form): void
	{
		$form->addText('more');
	}

	public function go(): void
	{
		dumpType($this['form']->getValues(true)); // => array{always: string, sometimes?: string, more: string}
	}

}
