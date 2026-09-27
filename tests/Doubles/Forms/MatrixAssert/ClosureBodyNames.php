<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\WidgetContainer;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

final class ClosureBodyNames
{

	public function c01(bool $flag): void
	{
		$form = new ApplicationForm();
		$name = 'outer';
		$form->addText($name);
		(function () use (&$form, $flag): void {
			if ($flag) {
				$name = 'inner1';
			} else {
				$name = 'inner2';
			}

			$form->addText($name);
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  outer: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function c02(): void
	{
		$form = new ApplicationForm();
		(function () use (&$form): void {
			$local = 'local';
			$form->addText($local);
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  local: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function c03(bool $flag): void
	{
		$form = new ApplicationForm();
		$name = 'outer';
		$form->addText($name);
		(function (ApplicationForm $f) use ($flag): void {
			if ($flag) {
				$name = 'inner1';
			} else {
				$name = 'inner2';
			}

			$f->addText($name);
		})($form);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  outer: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function c04(bool $flag): void
	{
		$form = new ApplicationForm();
		$name = 'outer';
		$form->addText($name);
		$form->addDynamic('rep', static function (FormContainer $c) use ($flag): void {
			if ($flag) {
				$name = 'inner1';
			} else {
				$name = 'inner2';
			}

			$c->addText($name);
		});
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  outer: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  rep: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    ...<IComponent>,
			  }>>+own*mixed*{
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

	public function c05(): void
	{
		$form = new ApplicationForm();
		$nullable = false;
		$form->addText('a')->setNullable($nullable);
		(function () use (&$form): void {
			$nullable = true;
			$form->addText('b')->setNullable($nullable);
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  b: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string|null>,
			}
			OUTPUT);
	}

	public function c06(): void
	{
		$form = new ApplicationForm();
		$nullable = true;
		$cb = function () use (&$form, $nullable): void {
			$form->addText('b')->setNullable($nullable);
		};
		$nullable = false;
		$cb();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  b: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string|null>,
			}
			OUTPUT);
	}

	public function c07(): void
	{
		$form = new ApplicationForm();
		$nullable = false;
		$cb = function () use (&$form, $nullable): void {
			$form->addText('b')->setNullable($nullable);
		};
		$cb();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  b: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function c08(): void
	{
		$form = new ApplicationForm();
		(function () use (&$form): void {
			$c = $form->addContainer('sub');
			$c->addText('x');
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT);
	}

	public function c09(): void
	{
		$form = new ApplicationForm();
		(function () use (&$form): void {
			$c = $form->addContainer('sub');
			$c->addDynamic('rep', static function (FormContainer $r): void {
				$r->addText('t');
			});
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    rep: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      t: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    }>>+own*mixed*{
			      ...<IComponent>,
			    },
			  },
			}
			OUTPUT);
	}

	public function c10(): void
	{
		$form = new ApplicationForm();
		$c = new WidgetContainer();
		(function () use (&$form): void {
			$c = $form->addContainer('sub');
			$c->addWidget('w');
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    w: *mixed*<*any*, *unknown*>,
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

	public function c11(): void
	{
		$form = new ApplicationForm();
		$v = $form->addContainer('a');
		(function () use (&$form): void {
			$v = $form->addContainer('b');
			$v->addText('x');
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{},
			  b: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT);
	}

	public function c12(bool $flag): void
	{
		$form = new ApplicationForm();
		$v = $form->addContainer('a');
		(function () use (&$form, $v, $flag): void {
			if ($flag) {
				$v = $form->addContainer('b');
			}

			$v->addText('x');
		})();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    ...<IComponent>,
			  },
			  b?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

	public function c13(): void
	{
		$form = new ApplicationForm();
		(function () use (&$form): void {
			$disabled = true;
			$form->addText('b')->setDisabled($disabled);
		})();
		// The disable flag is body-bound, so whether 'b' is omitted is unknown: the control
		// type is kept (component stays definite), and getValues() widens 'b' to nullable.
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  b: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
		assertFormValues($form, 'Nette\Utils\ArrayHash{b: string|null}');
	}

}
