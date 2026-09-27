<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Forms\Controls\TextInput;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class ExplicitAdd
{

	public function e01(): void
	{
		$form = new ApplicationForm();
		$form['a'] = new TextInput();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function e02(): void
	{
		$form = new ApplicationForm();
		$form->addComponent(new TextInput(), 'a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function e03(): void
	{
		$form = new ApplicationForm();
		$inner = new FormContainer();
		$inner->addText('x');
		$form['a'] = $inner;
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT);
	}

	public function e04(FormContainer $paramContainer): void
	{
		$form = new ApplicationForm();
		$form['a'] = $paramContainer;
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: *mixed*<*any*, *unknown*>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

}
