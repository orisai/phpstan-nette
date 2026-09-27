<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class HelperNoCallSiteStaysIComponent extends Control
{

	public function renderForm(ApplicationForm $form): void
	{
		dumpType($form['x']); // => Nette\ComponentModel\IComponent
	}

}
