<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Control;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

class FormFactory
{

	public function create(): ApplicationForm
	{
		return new ApplicationForm();
	}

}
