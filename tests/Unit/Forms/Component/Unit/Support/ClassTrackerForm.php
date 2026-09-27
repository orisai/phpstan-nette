<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class ClassTrackerForm extends ApplicationForm
{

	public function buildSelf(): void
	{
		$this->addHidden('id');
	}

}
