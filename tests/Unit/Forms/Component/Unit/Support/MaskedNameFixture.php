<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class MaskedNameFixture
{

	public function build(): void
	{
		$form = new ApplicationForm();
		$known = 'kv';
		$form->addText($known);
	}

}
