<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\Testing\assertType;

final class DeepNested
{

	public function g7_01(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$b = $a->addContainer('b');
		$b->addInteger('c');
		assertType(
			'Nette\Utils\ArrayHash{a: Nette\Utils\ArrayHash{b: Nette\Utils\ArrayHash{c: int|null}}}',
			$form->getValues(),
		);
	}

	public function g7_02(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$b = $a->addContainer('b');
		$b->addInteger('c');
		assertType('int|null', $form->getValues()->a->b->c);
	}

	public function g7_03(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$b = $a->addContainer('b');
		$b->addInteger('c');
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{b: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{c: int|null}}', $form['a']);
	}

	public function g7_04(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$b = $a->addContainer('b');
		$b->addInteger('c');
		assertType('Nette\Forms\Controls\TextInput', $form['a']['b']['c']);
	}

	public function g7_05(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$b = $a->addContainer('b');
		$b->addInteger('c');
		assertType('int|null', $form['a']['b']['c']->getValue());
	}

	public function g7_06(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}>', $form['d']);
	}

	public function g7_07(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}', $form['d'][0]);
	}

	public function g7_08(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('Nette\Forms\Controls\TextInput', $form['d'][0]['a']);
	}

	public function g7_09(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('string', $form['d'][0]['a']->getValue());
	}

	public function g7_10(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addInteger('a'));
		assertType('Nette\Utils\ArrayHash<Nette\Utils\ArrayHash{a: int|null}>', $form->getValues()->d);
	}

}
