<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Removal
{

	public function d01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		unset($form['a']);
		assertComponent($form, 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{}');
	}

	public function d02(bool $c): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		if ($c) {
			unset($form['a']);
		}
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function d03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->removeComponent($form['a']);
		assertComponent($form, 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{}');
	}

}
