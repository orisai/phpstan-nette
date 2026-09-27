<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use function PHPStan\dumpType;

final class MFormUnknownInputTrackedAsMixed extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addStarRating('rating');

		return $form;
	}

	public function go(): void
	{
		$values = $this['form']->getValues();
		dumpType($values); // => Nette\Utils\ArrayHash{name: string, rating: mixed, ...<mixed>}
	}

}
