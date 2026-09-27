<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MyWizard;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\PartlyOpaqueWizard;
use function OriPhpstan\Nette\Forms\Testing\dumpComponent;

final class WizardShape
{

	public function dumpResolvable(MyWizard $wizard): void
	{
		dumpComponent($wizard);
	}

	public function dumpPartlyOpaque(PartlyOpaqueWizard $wizard): void
	{
		dumpComponent($wizard);
	}

}
