<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Form;
use function PHPStan\Testing\assertType;

final class Cooperation
{

	public function g15_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('Nette\Utils\ArrayHash{a: string}', $form->getValues());
	}

	public function g15_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('array{a: string}', $form->getValues(true));
	}

	public function g15_03(Form $form): void
	{
		assertType('Nette\Utils\ArrayHash', $form->getValues());
	}

	public function g15_04(Form $form): void
	{
		assertType('array<string, mixed>', $form->getValues(true));
	}

	public function g15_05(ApplicationForm $form): void
	{
		assertType('Nette\Utils\ArrayHash', $form->getValues());
	}

}
