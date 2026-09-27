<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ReplicatorNestedInPlainContainerResolves extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		$outer->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('x');
		});

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['outer']['rep']); // => array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>
		dumpType($this['form']['outer']['rep']->getContainers()); // => Iterator<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>
	}

}
