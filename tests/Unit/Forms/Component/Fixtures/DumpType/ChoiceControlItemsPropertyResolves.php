<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ChoiceControlItemsPropertyResolves extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addRadioList('single', 'Label', [1 => 'a', 2 => 'b']);
		$form->addCheckboxList('multi', 'Label', [1 => 'a', 2 => 'b']);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['single']->items); // => array<int|string, mixed>
		dumpType($this['form']['multi']->items); // => array<int|string, mixed>
	}

}
