<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class AssertComponent
{

	public function matches(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function mismatches(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertComponent($form, 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{}');
	}

}
