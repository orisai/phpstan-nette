<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Container;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\UntaggedLookalikeForm;
use function PHPStan\dumpType;

final class MultiplierUntaggedLookalikeNotReplicator extends BaseFormControl
{

	protected function createComponentForm(): UntaggedLookalikeForm
	{
		$form = new UntaggedLookalikeForm();
		$form->addWidget('rep', static function (Container $c): void {
			$c->addText('x');
		});

		return $form;
	}

	public function offset(int $i): void
	{
		dumpType($this['form']['rep'][$i]); // => Nette\ComponentModel\IComponent
	}

	public function values(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{rep: mixed, ...<mixed>}
	}

}
