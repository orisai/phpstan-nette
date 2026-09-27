<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * A factory returning a different form in each branch: the component shape is the branch
 * join, so a field present in only one arm degrades to maybe-present and reads back as
 * nullable in getValues().
 */
final class MultiReturnFactory extends Control
{

	protected function createComponentThing(): ApplicationForm
	{
		if ($this->condition()) {
			$a = new ApplicationForm();
			$a->addText('alpha');

			return $a;
		}

		$b = new ApplicationForm();
		$b->addText('beta');

		return $b;
	}

	public function usage(): void
	{
		dumpType($this['thing']->getValues()); // => Nette\Utils\ArrayHash{alpha: string|null, beta: string|null}
	}

	private function condition(): bool
	{
		return true;
	}

}
