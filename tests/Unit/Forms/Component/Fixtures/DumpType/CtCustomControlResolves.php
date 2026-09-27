<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\BaseControl;
use function PHPStan\dumpType;

final class CtRatingControl extends BaseControl
{

}

final class CtForm extends RawForm
{

	public function addRating(string $name): CtRatingControl
	{
		$control = new CtRatingControl();
		$this[$name] = $control;

		return $control;
	}

}

final class CtCustomControlResolves extends Control
{

	protected function createComponentForm(): CtForm
	{
		$form = new CtForm();
		$form->addRating('stars');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['stars']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\CtRatingControl
	}

}
