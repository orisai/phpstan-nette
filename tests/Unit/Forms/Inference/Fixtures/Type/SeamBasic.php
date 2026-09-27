<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Form;
use function PHPStan\Testing\assertType;

final class SeamBasic
{

	public function intraProcedural(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string}', $form);
	}

	public function interproceduralDeferred(Form $form): void
	{
		assertType('Nette\Forms\Form', $form);
	}

}
