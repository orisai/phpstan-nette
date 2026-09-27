<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MClosureUseFormInRepCallback extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep', static function (FormContainer $c) use ($form): void {
			$c->addText('innerField');
			foreach ($form['rep']->getContainers() as $item) {
				dumpType($item['innerField']); // => Nette\Forms\Controls\TextInput
			}
		});

		return $form;
	}

}
