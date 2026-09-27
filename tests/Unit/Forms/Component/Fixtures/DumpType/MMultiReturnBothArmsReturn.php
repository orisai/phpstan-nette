<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MMultiReturnBothArmsReturn extends Control
{

	private bool $flag;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('common');

		if ($this->flag) {
			$form->addText('whenTrue');

			return $form;
		} else {
			$form->addText('whenFalse');

			return $form;
		}
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{common: string, whenTrue: string|null, whenFalse: string|null}
	}

}
