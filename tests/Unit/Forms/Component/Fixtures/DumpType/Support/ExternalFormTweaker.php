<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class ExternalFormTweaker
{

	public function tweak(ApplicationForm $form): void
	{
		$form->addText('fromTweaker');
	}

}
