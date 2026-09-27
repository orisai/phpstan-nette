<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MMultiReturnEarlyExit extends Control
{

	private bool $flag;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('common');

		if ($this->flag) {
			$form->addText('earlyOnly');

			return $form;
		}

		$form->addText('lateOnly');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{common: string, earlyOnly: string|null, lateOnly: string|null}
	}

}
