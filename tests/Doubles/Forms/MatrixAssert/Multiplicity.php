<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Multiplicity
{

	public function m01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function m02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addInteger('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<*any*, int|string|null>,
			}
			OUTPUT);
	}

	public function m03(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('a');
		} else {
			$form->addInteger('a');
		}
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<*any*, int|string|null>,
			}
			OUTPUT);
	}

	public function m04(bool $c): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		if ($c) {
			$form->addInteger('a');
		}
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<*any*, int|string|null>,
			}
			OUTPUT);
	}

	public function m05(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('a');
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{},
			}
			OUTPUT);
	}

}
