<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MappedStepWizard;
use function PHPStan\dumpType;

final class WizardMappedStep
{

	public function values(MappedStepWizard $wizard): void
	{
		dumpType($wizard->getValues()); // => array{1?: Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MappedFormDto}
	}

	public function step1Dto(MappedStepWizard $wizard): void
	{
		dumpType($wizard->getValues()[1]->name); // => string
	}

}
