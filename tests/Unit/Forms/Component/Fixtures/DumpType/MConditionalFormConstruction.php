<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MConditionalFormConstruction extends Control
{

	private bool $flag;

	protected function createComponentForm(): ApplicationForm
	{
		if ($this->flag) {
			$form = new ApplicationForm();
			$form->addText('a');
		} else {
			$form = new ApplicationForm();
			$form->addText('b');
		}

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{a: string|null, b: string|null}
	}

}
