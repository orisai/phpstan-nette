<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\UntaggedWizardLookalike;
use function PHPStan\dumpType;

final class WizardUntaggedLookalikeNotWizard
{

	public function values(UntaggedWizardLookalike $wizard): void
	{
		dumpType($wizard->getValues()); // => array<int, mixed>
	}

}
