<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ReplicatorUntypedParamStaysIComponent extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep', static function ($c): void {
			$c->addText('x');
		});

		return $form;
	}

	public function pick(int $i): void
	{
		dumpType($this['form']['rep'][$i]); // => Nette\ComponentModel\IComponent
	}

}
