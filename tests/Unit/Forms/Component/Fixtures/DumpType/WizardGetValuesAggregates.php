<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MyWizard;
use function PHPStan\dumpType;

final class WizardGetValuesAggregates
{

	public function values(MyWizard $wizard): void
	{
		dumpType($wizard->getValues()); // => array{1?: Nette\Utils\ArrayHash{username: string}, 2?: Nette\Utils\ArrayHash{email: string}}
	}

	public function step1Field(MyWizard $wizard): void
	{
		dumpType($wizard->getValues()[1]->username); // => string
	}

	public function step2Field(MyWizard $wizard): void
	{
		dumpType($wizard->getValues()[2]->email); // => string
	}

}
