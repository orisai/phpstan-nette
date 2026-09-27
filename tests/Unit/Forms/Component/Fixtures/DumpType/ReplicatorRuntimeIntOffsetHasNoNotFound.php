<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ReplicatorRuntimeIntOffsetHasNoNotFound extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});

		return $form;
	}

	public function go(int $i): void
	{
		dumpType($this['form']['rows'][$i]); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}
	}

}
