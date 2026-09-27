<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpTypePhp84;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * The first-class-callable spelling of an event-property store names the same method
 * `[$this, 'registersOnForm']` does, so it is read the same way: the handler registers, the
 * render-time shape is therefore unprovable and opens, and the deferred name degrades in silence
 * instead of being reported absent.
 */
final class EventStoredFirstClassCallable extends Control
{

	public function registersOnForm(ApplicationForm $f): void
	{
		$f->addText('viaHandler');
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess[] = $this->registersOnForm(...);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['viaHandler']); // => mixed
		dumpType($this['form']['base']); // => Nette\Forms\Controls\TextInput
	}

}
