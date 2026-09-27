<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Container;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MultiplierForm;
use function PHPStan\dumpType;

final class MultiplierThirdPartyResolves extends BaseFormControl
{

	protected function createComponentForm(): MultiplierForm
	{
		$form = new MultiplierForm();
		$form->addMultiplier('rep', static function (Container $c): void {
			$c->addText('x');
		});

		return $form;
	}

	public function offset(int $i): void
	{
		dumpType($this['form']['rep'][$i]); // => Nette\Forms\Container{x: string}
	}

	public function values(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{rep: Nette\Utils\ArrayHash<Nette\Utils\ArrayHash{x: string}>}
	}

}
