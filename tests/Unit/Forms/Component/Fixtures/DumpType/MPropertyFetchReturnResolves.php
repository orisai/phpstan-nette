<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MPropertyFetchReturnInner extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addHidden('bag');

		return $form;
	}

}

final class MPropertyFetchReturnResolves extends Control
{

	private MPropertyFetchReturnInner $inner;

	protected function createComponentChild(): MPropertyFetchReturnInner
	{
		return $this->inner;
	}

	public function go(): void
	{
		dumpType($this['child']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MPropertyFetchReturnInner
		dumpType($this['child']['form']['bag']); // => Nette\Forms\Controls\HiddenField
	}

}
