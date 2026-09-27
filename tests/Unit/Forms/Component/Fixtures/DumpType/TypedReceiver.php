<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class TypedReceiver extends Control
{

	protected function createComponentProfileForm(): Form
	{
		$form = new Form();
		$contact = $form->addContainer('contact');
		$contact->addText('email');

		return $form;
	}

}

function renderProfile(TypedReceiver $control): void
{
	dumpType($control['profileForm']['contact']['email']); // => Nette\Forms\Controls\TextInput
}
