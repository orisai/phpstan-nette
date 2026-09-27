<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

final class ConditionalDisable
{

	public function conditionallyDisabled(bool $cond): void
	{
		$form = new ApplicationForm();
		$control = $form->addText('a');
		if ($cond) {
			$control->setDisabled();
		}

		assertFormValues($form, 'Nette\Utils\ArrayHash{a: string|null}');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

}
