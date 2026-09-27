<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MEventLocalFormReceiverResolves extends Control
{

	protected function createComponentEditForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('localReceiverField');
		$form->onSuccess[] = [$this, 'handleEdit'];

		return $form;
	}

	public function handleEdit(ApplicationForm $form): void
	{
		dumpType($form['localReceiverField']); // => Nette\Forms\Controls\TextInput
	}

}
