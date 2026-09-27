<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class Sub extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addHidden('id');

		return $form;
	}

}

interface SubFactory
{

	public function create(): Sub;

}

final class Inner_TwoHopStaysIComponent extends BaseFormControl
{

	private SubFactory $factory;

	public function __construct(SubFactory $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addHidden('marker');

		return $form;
	}

	protected function createComponentSub(): Sub
	{
		$sub = $this->factory->create();

		return $sub;
	}

}

interface InnerFactory_TwoHopStaysIComponent
{

	public function create(): Inner_TwoHopStaysIComponent;

}

final class Outer_TwoHopStaysIComponent extends Control
{

	private InnerFactory_TwoHopStaysIComponent $factory;

	public function __construct(InnerFactory_TwoHopStaysIComponent $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentInner(): Inner_TwoHopStaysIComponent
	{
		$inner = $this->factory->create();

		return $inner;
	}

	public function go(): void
	{
		dumpType($this['inner']['sub']['form']['id']); // => Nette\ComponentModel\IComponent
	}

}
