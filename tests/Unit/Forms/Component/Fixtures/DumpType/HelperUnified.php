<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function PHPStan\dumpType;

final class HelperUnified extends Control
{

	protected function createComponentAddrForm(): Form
	{
		$form = new Form();
		$addr = $form->addContainer('addr');
		$addr->addText('street');
		$this->fill($form['addr']);

		return $form;
	}

	private function fill(Container $c): void
	{
		dumpType($c['street']); // => Nette\Forms\Controls\TextInput
	}

}
