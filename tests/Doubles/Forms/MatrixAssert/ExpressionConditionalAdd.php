<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

final class ExpressionConditionalAdd
{

	public function assignWrappedTernary(bool $c): void
	{
		$form = new ApplicationForm();
		$field = $c ? $form->addText('a') : $form->addText('b');

		assertFormValues($form, 'Nette\Utils\ArrayHash{a: string|null, b: string|null}');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  b?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function booleanShortCircuit(bool $c): void
	{
		$form = new ApplicationForm();
		$c && $form->addText('a');

		assertFormValues($form, 'Nette\Utils\ArrayHash{a: string|null}');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function conditionalModifierViaChainedTernary(bool $c): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$c ? $form->addText('b') : null;

		assertFormValues($form, 'Nette\Utils\ArrayHash{a: string, b: string|null}');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  b?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function nestedGuardInsideTernaryArm(bool $c, bool $d): void
	{
		$form = new ApplicationForm();
		$c ? ($d && $form->addText('a')) : $form->addText('a');

		assertFormValues($form, 'Nette\Utils\ArrayHash{a: string|null}');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

}
