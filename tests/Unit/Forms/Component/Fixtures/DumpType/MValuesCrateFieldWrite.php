<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class MValuesCrateFieldWrite extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');

		return $form;
	}

	public function go(): void
	{
		$values = $this['form']->getValues();
		$values->name = 'renamed';
		$values->nope = 'x';
		dumpType($values->name); // => string
		$values->name = 1;

		$other = new MValuesCrateFieldWriteReadOnly();
		$other->value = 'x'; // !! Property Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MValuesCrateFieldWriteReadOnly::$value is not writable.
	}

}

/**
 * @property-read string $value
 */
final class MValuesCrateFieldWriteReadOnly
{

	public function __get(string $name): string
	{
		return $name;
	}

}
