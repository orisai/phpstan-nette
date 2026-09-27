<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MBuilderChainNonFormControlInner extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addHidden('id');

		return $form;
	}

}

interface MBuilderChainNonFormControlFactory
{

	public function create(): MBuilderChainNonFormControlInner;

}

final class MBuilderChainNonFormControlResolves extends Control
{

	private MBuilderChainNonFormControlFactory $factory;

	protected function createComponentChild(): MBuilderChainNonFormControlInner
	{
		return $this->factory->create();
	}

	public function go(): void
	{
		dumpType($this['child']['form']['id']); // => Nette\Forms\Controls\HiddenField
	}

}
