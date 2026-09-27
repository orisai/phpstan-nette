<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MFactoryCallReturnFactory
{

	public function create(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('fromFactory');

		return $form;
	}

}

final class MFactoryCallReturnResolves extends Control
{

	private MFactoryCallReturnFactory $factory;

	protected function createComponentForm(): ApplicationForm
	{
		return $this->factory->create();
	}

	public function go(): void
	{
		dumpType($this['form']['fromFactory']); // => Nette\Forms\Controls\TextInput
	}

}
