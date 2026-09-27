<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Dto;
use function PHPStan\Testing\assertType;

final class MappedType
{

	public function g5_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addInteger('age');
		assertType('Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Dto', $form->getValues(Dto::class));
	}

	public function g5_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addInteger('age');
		assertType('string', $form->getValues(Dto::class)->name);
	}

	public function g5_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addInteger('age');
		assertType('int|null', $form->getValues(Dto::class)->age);
	}

	public function g5_04(): void
	{
		$form = new ApplicationForm();
		$form->setMappedType(Dto::class);
		$form->addText('name');
		assertType('Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Dto', $form->getValues());
	}

	public function g5_05(): void
	{
		$form = new ApplicationForm();
		$form->setMappedType(Dto::class);
		$form->addText('name');
		assertType('string', $form->getValues()->name);
	}

	public function g5_06(): void
	{
		$form = new ApplicationForm();
		$form->setMappedType(Dto::class);
		$form->addText('name');
		$form->addInteger('age');
		assertType('int|null', $form->getValues()->age);
	}

	public function g5_07(): void
	{
		$form = new ApplicationForm();
		$form->setMappedType(Dto::class);
		$form->addText('name');
		assertType('array{name: string}', $form->getValues(true));
	}

	public function g5_08(): void
	{
		$form = new ApplicationForm();
		$form->setMappedType(Dto::class);
		$form->addText('name');
		assertType('Nette\Utils\ArrayHash{name: string}', $form->getUntrustedValues());
	}

}
