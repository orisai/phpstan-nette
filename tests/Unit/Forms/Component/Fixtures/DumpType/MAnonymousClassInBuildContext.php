<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MAnonymousClassInBuildContext extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('anonField');

		$anon = new class {

			public function noop(): void
			{
			}

		};
		$anon->noop();

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['anonField']); // => Nette\Forms\Controls\TextInput
	}

}
