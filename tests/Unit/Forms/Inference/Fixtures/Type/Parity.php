<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\Testing\assertType;

final class Parity
{

	public function g14_04(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string}', $form);
	}

	public function g14_05(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('Nette\Utils\ArrayHash{a: string}', $form->getValues());
	}

	public function g14_06(): void
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('c');
		$c->addInteger('b');
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{c: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{b: int|null}}', $form);
	}

}
