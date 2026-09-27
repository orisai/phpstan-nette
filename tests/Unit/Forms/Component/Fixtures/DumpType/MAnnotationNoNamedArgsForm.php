<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * @no-named-arguments
 */
final class MAnnotationNoNamedArgsForm extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('positionalField');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['positionalField']); // => Nette\Forms\Controls\TextInput
	}

}
