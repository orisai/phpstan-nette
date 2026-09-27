<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class Inner_ClassFactoryServiceOneHop extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addHidden('id');

		return $form;
	}

}

interface InnerFactory_ClassFactoryServiceOneHop
{

	public function create(): Inner_ClassFactoryServiceOneHop;

}

final class Outer_ClassFactoryServiceOneHop extends Control
{

	private InnerFactory_ClassFactoryServiceOneHop $factory;

	public function __construct(InnerFactory_ClassFactoryServiceOneHop $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentInner(): Inner_ClassFactoryServiceOneHop
	{
		$inner = $this->factory->create();

		return $inner;
	}

	public function go(): void
	{
		dumpType($this['inner']['form']['id']); // => Nette\Forms\Controls\HiddenField
	}

}
