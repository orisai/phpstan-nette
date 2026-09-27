<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class FmChainControl extends Control
{

	public function createForm(?int $a): Form
	{
		$form = new Form();
		$form->addHidden('action');

		return $form;
	}

}

interface FmChainControlFactory
{

	public function create(): FmChainControl;

}

final class FmTwoHopChainResolves extends Control
{

	private FmChainControlFactory $factory;

	public function go(): void
	{
		$f = $this->factory->create()->createForm(1);
		dumpType($f['action']); // => Nette\Forms\Controls\HiddenField
	}

}
