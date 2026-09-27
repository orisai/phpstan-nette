<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function PHPStan\dumpType;

final class DivergentCallsitesStayIComponent extends Control
{

	protected function createComponentA(): Form
	{
		$form = new Form();
		$x = $form->addContainer('x');
		$x->addText('p');
		$this->fill($form['x']);

		return $form;
	}

	protected function createComponentB(): Form
	{
		$form = new Form();
		$y = $form->addContainer('y');
		$y->addTextArea('q');
		$this->fill($form['y']);

		return $form;
	}

	private function fill(Container $c): void
	{
		dumpType($c['p']); // => Nette\ComponentModel\IComponent
	}

}
