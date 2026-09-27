<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ReplicatorNonEnumerableStaysIComponent extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep', [$this, 'fillRow']);

		return $form;
	}

	public function fillRow(FormContainer $c): void
	{
		$c->addText('x');
	}

	public function go(): void
	{
		dumpType($this['form']['rep'][0]); // => Nette\ComponentModel\IComponent
	}

}
