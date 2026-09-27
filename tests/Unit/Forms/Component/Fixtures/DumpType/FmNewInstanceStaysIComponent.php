<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class FmNewInstanceForm extends Form
{

}

final class FmNewInstanceControl extends Control
{

	public function createForm(): Form
	{
		return new FmNewInstanceForm();
	}

}

final class FmNewInstanceStaysIComponent extends Control
{

	private FmNewInstanceControl $inner;

	public function go(): void
	{
		$f = $this->inner->createForm();
		dumpType($f['action']); // => Nette\ComponentModel\IComponent
	}

}
