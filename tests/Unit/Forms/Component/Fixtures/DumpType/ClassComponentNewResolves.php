<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class ClassComponentNewResolves extends Control
{

	protected function createComponentEdit(): Form
	{
		$f = new Form();
		$f->addText('name');

		return $f;
	}

	public function go(): void
	{
		dumpType($this['edit']['name']); // => Nette\Forms\Controls\TextInput
	}

}
