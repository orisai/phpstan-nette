<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MEventArrayCallableResolves extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('arrayCallableField');
		$form->onSuccess[] = [$this, 'handleSuccess'];

		return $form;
	}

	public function handleSuccess(ApplicationForm $form): void
	{
		dumpType($form['arrayCallableField']); // => Nette\Forms\Controls\TextInput
	}

}
