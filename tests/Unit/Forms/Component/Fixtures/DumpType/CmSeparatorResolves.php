<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class CmSeparatorControl extends Control
{

	public function createForm(): Form
	{
		$form = new Form();
		$foo = $form->addContainer('foo');
		$bar = $foo->addContainer('bar');
		$bar->addText('baz');

		return $form;
	}

}

final class CmSeparatorResolves extends Control
{

	private CmSeparatorControl $inner;

	public function go(): void
	{
		$a = $this->inner->createForm();

		dumpType($a['foo']['bar']['baz']); // => Nette\Forms\Controls\TextInput
		dumpType($a->getComponent('foo')->getComponent('bar')->getComponent('baz')); // => Nette\Forms\Controls\TextInput
		dumpType($a->getComponent('foo')['bar']->getComponent('baz')); // => Nette\Forms\Controls\TextInput
		dumpType($a['foo-bar-baz']); // => Nette\Forms\Controls\TextInput
		dumpType($a->getComponent('foo-bar-baz')); // => Nette\Forms\Controls\TextInput
	}

}
