<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\OpaqueLeafForm;
use function PHPStan\dumpType;

final class DisabledOpaqueControl extends Control
{

	protected function createComponentForm(): OpaqueLeafForm
	{
		$form = new OpaqueLeafForm();
		$form->addText('a');
		$form->addOpaque('b')->setDisabled();

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues(true)); // => array{a: string, ...<string, mixed>}
	}

}
