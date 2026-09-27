<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class FmUndeclaredControl extends Control
{

	public function createForm()
	{
		$form = new Form();
		$form->addHidden('action');

		return $form;
	}

}

final class FmUndeclaredReturnStaysIComponent extends Control
{

	private FmUndeclaredControl $inner;

	public function go(): void
	{
		$f = $this->inner->createForm();
		dumpType($f['action']); // => mixed
	}

}
