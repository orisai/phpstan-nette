<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;

final class MappedTypeNested extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$address = $form->addContainer('address');
		$address->addText('street');
		$address->addText('city');

		return $form;
	}

	public function nestedGood(): void
	{
		$this['form']->getValues(NestedOuterGood::class);
	}

	public function nestedBadField(): void
	{
		$this['form']->getValues(NestedOuterBadField::class);
	}

	public function containerToScalar(): void
	{
		$this['form']->getValues(NestedOuterScalar::class);
	}

}
