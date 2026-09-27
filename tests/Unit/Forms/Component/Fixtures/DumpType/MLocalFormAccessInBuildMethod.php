<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MLocalFormAccessInBuildMethod extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$bankContainer = $form->addContainer('bank');
		$bankContainer->addText('account_number');

		dumpType($form['bank']['account_number']); // => Nette\Forms\Controls\TextInput

		return $form;
	}

}
