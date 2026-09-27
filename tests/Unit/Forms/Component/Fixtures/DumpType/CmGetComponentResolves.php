<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class CmGetComponentControl extends Control
{

	public function createForm(?int $a): Form
	{
		$form = new Form();
		$form->addHidden('action');
		if ($a !== null) {
			$form->addText('opt');
		}

		return $form;
	}

}

final class CmGetComponentResolves extends Control
{

	private CmGetComponentControl $inner;

	public function go(): void
	{
		$f = $this->inner->createForm(1);
		dumpType($f->getComponent('action')); // => Nette\Forms\Controls\HiddenField
		dumpType($f->getComponent('opt')); // => Nette\Forms\Controls\TextInput
	}

}
