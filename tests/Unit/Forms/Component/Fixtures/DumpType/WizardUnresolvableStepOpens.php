<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\PartlyOpaqueWizard;
use function PHPStan\dumpType;

final class WizardUnresolvableStepOpens
{

	public function values(PartlyOpaqueWizard $wizard): void
	{
		dumpType($wizard->getValues()); // => array{1?: Nette\Utils\ArrayHash{username: string}, 2?: Nette\Utils\ArrayHash{blob: mixed, ...<mixed>}}
	}

}
