<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class CmGcDelegateInner extends Control
{

	public function createForm(): Form
	{
		$form = new Form();
		$form->addHidden('action');

		return $form;
	}

}

final class CmGcDelegate extends Control
{

	private CmGcDelegateInner $delegate;

	public function createForm(): Form
	{
		return $this->delegate->createForm();
	}

}

final class CmGetComponentDelegationResolves extends Control
{

	private CmGcDelegate $inner;

	public function go(): void
	{
		$f = $this->inner->createForm();
		dumpType($f->getComponent('action')); // => Nette\Forms\Controls\HiddenField
	}

}
