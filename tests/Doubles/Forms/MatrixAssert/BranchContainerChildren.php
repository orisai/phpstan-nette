<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

final class BranchContainerChildren
{

	public function branchedContainerChildren(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$sub = $form->addContainer('sub');
			$sub->addText('x');
		} else {
			$sub = $form->addContainer('sub');
			$sub->addText('y');
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    y?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT);

		assertFormValues(
			$form,
			'Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{x: string|null, y: string|null}}',
		);
	}

	public function branchedReplicatorChildren(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$form->addDynamic('rep', function (FormContainer $c): void {
				$c->addText('x');
			});
		} else {
			$form->addDynamic('rep', function (FormContainer $c): void {
				$c->addText('y');
			});
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  rep: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    y?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  }>>+own*mixed*{
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

	public function branchedTwoLevelNestedContainers(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$sub = $form->addContainer('sub');
			$inner = $sub->addContainer('inner');
			$inner->addText('x');
		} else {
			$sub = $form->addContainer('sub');
			$inner = $sub->addContainer('inner');
			$inner->addText('y');
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    inner: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      x?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			      y?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    },
			  },
			}
			OUTPUT);

		assertFormValues(
			$form,
			'Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{inner: Nette\Utils\ArrayHash{x: string|null, y: string|null}}}',
		);
	}

	public function branchedNestedContainerAndReplicator(bool $cond): void
	{
		$form = new ApplicationForm();
		if ($cond) {
			$sub = $form->addContainer('sub');
			$inner = $sub->addContainer('inner');
			$inner->addText('x');
		} else {
			$sub = $form->addContainer('sub');
			$sub->addDynamic('rep', function (FormContainer $c): void {
				$c->addText('y');
			});
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    inner?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    },
			    rep?: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      y: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    }>>+own*mixed*{
			      ...<IComponent>,
			    },
			  },
			}
			OUTPUT);

		assertFormValues(
			$form,
			'Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{inner: Nette\Utils\ArrayHash{x: string}|null, rep: Nette\Utils\ArrayHash<Nette\Utils\ArrayHash{y: string}>|null}}',
		);
	}

}
