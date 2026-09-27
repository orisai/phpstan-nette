<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Dto;
use function PHPStan\Testing\assertType;

final class KillSwitch
{

	public function g17_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('Nette\Utils\ArrayHash', $form->getValues());
	}

	public function g17_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('array<string, mixed>', $form->getValues(true));
	}

	public function g17_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertType('Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Dto', $form->getValues(Dto::class));
	}

}
