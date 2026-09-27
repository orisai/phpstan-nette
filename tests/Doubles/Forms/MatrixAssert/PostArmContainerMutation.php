<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class PostArmContainerMutation
{

	public function containerVarMutatedAfterBranch(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$s = $form->addContainer('s');
			$s->addText('x');
		}

		$s->addText('y');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  s?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

	public function unreferencedContainerVarStaysClosed(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$s = $form->addContainer('s');
			$s->addText('x');
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  s?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT);
	}

	public function chainedContainerVarMutatedAfterBranch(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$s = $form->addContainer('s')->setDefaults([]);
			$s->addText('x');
		}

		$s->addText('y');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  s?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

}
