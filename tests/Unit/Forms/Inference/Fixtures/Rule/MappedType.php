<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;

final class MappedType extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addInteger('age');

		return $form;
	}

	public function good(): void
	{
		$this['form']->getValues(MappedDtoGood::class);
	}

	public function missingProperty(): void
	{
		$this['form']->getValues(MappedDtoMissingProperty::class);
	}

	public function nonPublic(): void
	{
		$this['form']->getValues(MappedDtoNonPublic::class);
	}

	public function badType(): void
	{
		$this['form']->getValues(MappedDtoBadType::class);
	}

	public function abstractDto(): void
	{
		$this['form']->getValues(MappedDtoAbstract::class);
	}

	public function constructorOk(): void
	{
		$this['form']->getValues(MappedDtoConstructor::class);
	}

	public function constructorMissingParam(): void
	{
		$this['form']->getValues(MappedDtoConstructorMissingParam::class);
	}


	public function uninitializedRequired(): void
	{
		$this['form']->getValues(MappedDtoUninitializedRequired::class);
	}

	public function dynamicProps(): void
	{
		$this['form']->getValues(MappedDtoDynamic::class);
	}

}
