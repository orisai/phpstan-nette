<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\Gap5ParentControl;
use function PHPStan\dumpType;

/**
 * The parent rebind ($form = parent::createComponentForm()) is compensated interprocedurally,
 * but the offset-read after it ($sub = $form['fromParent']) is a genuine lost-field unknown:
 * the compensation must clear only the rebind marker, leaving the shape OPEN — a closed
 * {fromChild, fromParent} here would false-close over the pulled-out child's mutations.
 * The compensation also requires the parent call to be the SOLE rebind: followed by a
 * property rebind, the runtime form is the prebuilt one, so no parent field may be claimed.
 */
final class MParentRebindOffsetReadStaysOpen extends Gap5ParentControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = parent::createComponentForm();
		$sub = $form['fromParent'];
		$form->addText('fromChild');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{fromChild: string, ...<mixed>}
	}

}

final class MParentThenPropertyRebindStaysOpen extends Gap5ParentControl
{

	private ApplicationForm $prebuilt;

	protected function createComponentForm(): ApplicationForm
	{
		$form = parent::createComponentForm();
		$form = $this->prebuilt;
		$form->addText('local');
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(ApplicationForm $form): void
	{
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string, ...<mixed>}
	}

}
