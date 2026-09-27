<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\Testing\assertType;

final class GetValuesArray
{

	public function g4_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('array{p: string}', $form->getValues(true));
	}

	public function g4_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('array{p: string}', $form->getValues('array'));
	}

	public function g4_03(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('p');
		assertType('array{p: int|null}', $form->getValues(true));
	}

	public function g4_04(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		$form->addCheckbox('q');
		assertType('array{p: string, q: bool}', $form->getValues(true));
	}

	public function g4_05(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$a->addText('b');
		assertType('array{a: array{b: string}}', $form->getValues(true));
	}

	public function g4_06(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('array{d: array<int, array{a: string}>}', $form->getValues(true));
	}

	public function g4_07(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('p');
		}

		assertType('array{p?: string}', $form->getValues(true));
	}

	public function g4_08(): void
	{
		$form = new ApplicationForm();
		assertType('array{}', $form->getValues(true));
	}

	public function g4_09(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('p');
		assertType('array{p: list<int|string>}', $form->getValues(true));
	}

	public function g4_10(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('string', $form->getValues(true)['p']);
	}

	public function g4_11(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B']);
		assertType("array{s: 'a'|'b'|null}", $form->getValues(true));
	}

	public function g4_12(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('s', 'l', ['a' => 'A', 'b' => 'B'])->setRequired();
		assertType("array{s: 'a'|'b'|null}", $form->getValues(true));
	}

	public function g4_13(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('m', 'l', ['x' => 'X', 'y' => 'Y']);
		assertType("array{m: list<'x'|'y'>}", $form->getValues(true));
	}

}
