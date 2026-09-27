<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

interface CmChainControlFactory
{

	public function create(): CmChainControl;

}

final class CmChainControl extends Control
{

	public function createForm(): Form
	{
		$form = new Form();
		$form->addHidden('action');

		return $form;
	}

}

final class CmChainResolves extends Control
{

	public function go(CmChainControlFactory $factory): void
	{
		$form = $factory->create()->createForm();
		dumpType($form['action']); // => Nette\Forms\Controls\HiddenField
		dumpType($form->getComponent('action')); // => Nette\Forms\Controls\HiddenField
	}

}
