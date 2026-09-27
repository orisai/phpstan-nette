<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\Testing\assertType;

final class Aliases
{

	public function g6_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('Nette\Utils\ArrayHash{p: string}', $form->getUntrustedValues());
	}

	public function g6_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('Nette\Utils\ArrayHash{p: string}', $form->getUnsafeValues());
	}

	public function g6_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('Nette\Utils\ArrayHash{p: string}', $form->values);
	}

	public function g6_04(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('array{p: string}', $form->getUntrustedValues(true));
	}

	public function g6_05(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('string', $form->getUntrustedValues()->p);
	}

	public function g6_06(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$a->addInteger('b');
		assertType('int|null', $form->getUnsafeValues()->a->b);
	}

}
