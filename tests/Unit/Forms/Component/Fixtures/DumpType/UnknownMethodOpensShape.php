<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class UnknownMethodOpensShape extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$form->magicExtensionThing(); // @phpstan-ignore method.notFound (a magic/extensionMethod call we cannot follow)

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues(true)); // => array{known: string, ...<string, mixed>}
	}

}
