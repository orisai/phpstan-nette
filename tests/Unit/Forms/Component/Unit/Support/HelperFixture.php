<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;

final class HelperFixture extends Control
{

	protected function createComponentAddrForm(): Form
	{
		$form = new Form();
		$addr = $form->addContainer('addr');
		$addr->addText('street');
		$this->fill($form['addr']);

		return $form;
	}

	protected function createComponentOne(): Form
	{
		$form = new Form();
		$x = $form->addContainer('x');
		$x->addText('p');
		$this->diverge($form['x']);

		return $form;
	}

	protected function createComponentTwo(): Form
	{
		$form = new Form();
		$y = $form->addContainer('y');
		$y->addText('q');
		$this->diverge($form['y']);

		return $form;
	}

	private function fill(Container $c): void
	{
	}

	private function diverge(Container $c): void
	{
	}

}
