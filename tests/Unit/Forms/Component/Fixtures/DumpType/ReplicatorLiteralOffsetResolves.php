<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ReplicatorLiteralOffsetResolves extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('x');
		});

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['rep'][0]['x']); // => Nette\Forms\Controls\TextInput
	}

}
