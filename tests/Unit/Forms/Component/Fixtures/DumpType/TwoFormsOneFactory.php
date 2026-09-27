<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * Two forms are built in one factory but only $main is returned as the component. The
 * onSuccess handler is bound on $filter, so the handler parameter must not inherit the
 * returned component ($main) shape — receiver matching skips the registration and the
 * parameter stays open.
 */
final class TwoFormsOneFactory extends Control
{

	protected function createComponentMain(): ApplicationForm
	{
		$filter = new ApplicationForm();
		$filter->addText('filterField');
		$filter->onSuccess[] = [$this, 'filterSubmitted'];

		$main = new ApplicationForm();
		$main->addText('mainField');

		return $main;
	}

	public function filterSubmitted(ApplicationForm $f): void
	{
		dumpType($f['mainField']); // => Nette\ComponentModel\IComponent
		dumpType($f['filterField']); // => Nette\ComponentModel\IComponent
	}

}
