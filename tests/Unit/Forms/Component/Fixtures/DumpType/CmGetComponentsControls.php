<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * getComponents()/getControls() narrow to the union of the children the shape holds. The third case
 * is the one worth keeping: reading the form into a local first used to lose the narrowing, because
 * the form-level access handed back the bare class and the local carried nothing for the iterator
 * extension to read. It carries the shape now, so all three spellings agree — and the spelling that
 * used to lose it is the one every .latte writes.
 */
final class CmGetComponentsControls extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addText('email');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getComponents()); // => Iterator<int|string, Nette\Forms\Controls\TextInput>
		dumpType($this['form']->getControls()); // => Iterator<int|string, Nette\Forms\Controls\TextInput>
		$form = $this['form'];
		dumpType($form->getControls()); // => Iterator<int|string, Nette\Forms\Controls\TextInput>
	}

}
