<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MyWizard;
use function PHPStan\dumpType;

final class WizardOffsetAssignedIntoForm extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form['wiz'] = new MyWizard();

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['wiz']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MyWizard
		dumpType($this['form']['wiz']->getValues()); // => array{1?: Nette\Utils\ArrayHash{username: string}, 2?: Nette\Utils\ArrayHash{email: string}}
	}

}
