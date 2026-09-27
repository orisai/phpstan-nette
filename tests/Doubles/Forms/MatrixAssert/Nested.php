<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Nested
{

	public function x01(): void
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('c');
		$c->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  c: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT);
	}

	public function x02(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$b = $a->addContainer('b');
		$b->addInteger('c');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    b: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      c: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, int|null>,
			    },
			  },
			}
			OUTPUT);
	}

	public function x03(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  d: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  }>>+own*mixed*{
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

	public function x04(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$c = $form->addContainer('c');
			$c->addText('a');
		}
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  c?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT);
	}

	/**
	 * The item factory is a variable rather than an inline closure, so the walk cannot enumerate the
	 * ROW at all - which is why the row is OPEN here and closed in x03. Closed-and-empty would be a
	 * positive claim that the replicator's items have no fields, and $form['d'][0]['anything'] then
	 * read as a non-existent component on correct code (InferenceExistenceCheckRuleTest's EC-30
	 * pins the false positive itself). That this fixture's own factory happens to add nothing is
	 * something only a reader of the fixture knows; the analyzer never opens the variable.
	 */
	public function x05(): void
	{
		$form = new ApplicationForm();
		$factory = function (FormContainer $c) {
		};
		$form->addDynamic('d', $factory);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  d: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    ...<IComponent>,
			  }>>+own*mixed*{
			    ...<IComponent>,
			  },
			  ...<IComponent>,
			}
			OUTPUT);
	}

}
