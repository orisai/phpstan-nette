<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\OpaqueLeafForm;
use function PHPStan\dumpType;

final class OpenCratePropertyAccess extends Control
{

	protected function createComponentForm(): OpaqueLeafForm
	{
		$form = new OpaqueLeafForm();
		$form->addText('known');
		$form->addOpaque('opaque'); // an unfollowable add opens the shape

		return $form;
	}

	public function go(): void
	{
		$values = $this['form']->getValues();
		dumpType($values->known); // => string
		dumpType($values->mysteryField); // => mixed
	}

}
