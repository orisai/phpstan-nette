<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Forms\Form;
use function PHPStan\dumpType;

final class DiParamStaysIComponent
{

	public function process(Form $form): void
	{
		dumpType($form['anything']); // => Nette\ComponentModel\IComponent
	}

}
