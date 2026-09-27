<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MNestedCreateComponentInner extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addHidden('id');

		return $form;
	}

}

final class MNestedCreateComponentResolves extends Control
{

	private MNestedCreateComponentInner $inner;

	protected function createComponentChild(): MNestedCreateComponentInner
	{
		$cmp = $this->inner;

		return $cmp;
	}

	public function go(): void
	{
		// The leading backslash this line used to carry was the only place a FormShape class name
		// reached a type description unnormalised; FormShape now spells every class name bare.
		dumpType($this['child']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MNestedCreateComponentInner
		dumpType($this['child']['form']['id']); // => Nette\Forms\Controls\HiddenField
	}

}
