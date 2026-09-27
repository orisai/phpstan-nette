<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\OpaqueLeafForm;
use function PHPStan\dumpType;

final class OpenCrateUnsealedOffset extends Control
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
		dumpType($values); // => Nette\Utils\ArrayHash{known: string, opaque: mixed, ...<mixed>}
		dumpType($values['known']); // => string
		dumpType($values['whoKnows']); // => mixed
	}

}
