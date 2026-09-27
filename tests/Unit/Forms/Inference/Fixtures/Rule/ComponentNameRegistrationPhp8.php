<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

/**
 * Split out of ComponentNameRegistration because a named argument is PHP 8 syntax and `make lint`
 * parses every fixture on the project's oldest supported runtime; this file is excluded there, the
 * same way every other PHP-8-only fixture in this repo is.
 */
final class ComponentNameRegistrationPhp8
{

	public function namedArgumentIsFoundByItsPARAMETERName(): void
	{
		$form = new ApplicationForm();
		$form->addText(name: 'bad name');
	}

}
