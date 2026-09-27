<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MultiplierForm;
use function PHPStan\dumpType;

final class MultiplierUnresolvableFactoryOpens extends BaseFormControl
{

	protected function createComponentForm(): MultiplierForm
	{
		$form = new MultiplierForm();
		$form->addMultiplier('rep', [$this, 'fillRow']);

		return $form;
	}

	public function fillRow(): void
	{
	}

	public function offset(int $i): void
	{
		dumpType($this['form']['rep'][$i]); // => Nette\ComponentModel\IComponent
	}

}
