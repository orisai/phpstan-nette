<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use function PHPStan\dumpType;

/**
 * Same-named locals inside closures bind shadowed variables, not the method-level form: the
 * stored shape must not absorb the closure factory's or the closure constructor's fields.
 */
class SideFactory_MClosureLocalFactory
{

	public function create(): ApplicationForm
	{
		$f = new ApplicationForm();
		$f->addText('polluted');

		return $f;
	}

}

class CtorForm_MClosureLocalFactory extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$this->addText('ctorPolluted');
	}

}

final class MClosureLocalFactoryDoesNotPollute extends BaseFormControl
{

	private SideFactory_MClosureLocalFactory $sideFactory;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('real');
		$form->onSuccess[] = [$this, 'process'];
		$form->onSuccess[] = function (): void {
			$form = $this->sideFactory->create();
			$form->addText('extra');
		};

		return $form;
	}

	public function process(ApplicationForm $form): void
	{
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{real: string}
	}

}

final class MClosureLocalCtorDoesNotPollute extends BaseFormControl
{

	private MainFactory_MClosureLocalCtor $mainFactory;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->mainFactory->create();
		$form->addText('own');
		$form->onSuccess[] = [$this, 'process'];
		$form->onSuccess[] = static function (): void {
			$form = new CtorForm_MClosureLocalFactory();
			$form->addText('extra');
		};

		return $form;
	}

	public function process(ApplicationForm $form): void
	{
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{own: string, base: string}
	}

}

class MainFactory_MClosureLocalCtor
{

	public function create(): ApplicationForm
	{
		$f = new ApplicationForm();
		$f->addText('base');

		return $f;
	}

}
