<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * Multiple rebinds of the tracked form: the runtime form is the LAST binding, so the
 * compensation must not resolve an earlier factory assignment and claim its 'a' field.
 * Rebinds of EVERY family count toward the ambiguity — factory-then-factory,
 * factory-then-property, factory-then-foreach and factory-then-by-ref stay open (the
 * marker survives), factory-then-new is a clean reset to the fresh form's own fields with
 * no phantom 'a' merged over it.
 */
class FactoryA_MTwoFactoryRebinds
{

	public function create(): ApplicationForm
	{
		$f = new ApplicationForm();
		$f->addText('a');

		return $f;
	}

}

class FactoryB_MTwoFactoryRebinds
{

	private ApplicationForm $prebuilt;

	public function create(): ApplicationForm
	{
		return $this->prebuilt;
	}

}

final class MTwoFactoryRebindsStayOpen extends Control
{

	private FactoryA_MTwoFactoryRebinds $fa;

	private FactoryB_MTwoFactoryRebinds $fb;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->fa->create();
		$form = $this->fb->create();
		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string, ...<mixed>}
	}

}

final class MFactoryThenPropertyRebindStaysOpen extends Control
{

	private FactoryA_MTwoFactoryRebinds $fa;

	private ApplicationForm $prebuilt;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->fa->create();
		$form = $this->prebuilt;
		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string, ...<mixed>}
	}

}

final class MFactoryThenNewRebindKeepsFresh extends Control
{

	private FactoryA_MTwoFactoryRebinds $fa;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->fa->create();
		$form = new ApplicationForm();
		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string}
	}

}

final class MFactoryThenForeachRebindStaysOpen extends Control
{

	private FactoryA_MTwoFactoryRebinds $fa;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->fa->create();
		foreach ($this->formList() as $form) {
			$form->addText('loop');
		}

		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{loop: string|null, local: string, ...<mixed>}
	}

	/**
	 * @return list<ApplicationForm>
	 */
	private function formList(): array
	{
		return [];
	}

}

final class MFactoryThenRefRebindStaysOpen extends Control
{

	private FactoryA_MTwoFactoryRebinds $fa;

	protected function createComponentForm(): ApplicationForm
	{
		$other = new ApplicationForm();
		$form = $this->fa->create();
		$form =& $other;
		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string, ...<mixed>}
	}

}
