<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MyWizard;
use function PHPStan\dumpType;

final class WizardViaComponentTree extends Control
{

	protected function createComponentWizard(): MyWizard
	{
		return new MyWizard();
	}

	public function go(): void
	{
		dumpType($this['wizard']->getValues()); // => array{1?: Nette\Utils\ArrayHash{username: string}, 2?: Nette\Utils\ArrayHash{email: string}}
	}

}
