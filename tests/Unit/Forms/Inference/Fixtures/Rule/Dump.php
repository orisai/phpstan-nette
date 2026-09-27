<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;
use function OriPhpstan\Nette\Forms\Testing\dumpComponent;

final class Dump
{

	// G14-01: Dumped type: Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} [phpstan.dumpType]
	public function g14_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		dumpType($form);
	}

	public function g14_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		dumpComponent($form);
	}

	// G14-03: Dumped type: Nette\Utils\ArrayHash{a: string} [phpstan.dumpType]
	public function g14_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		dumpType($form->getValues());
	}

}
