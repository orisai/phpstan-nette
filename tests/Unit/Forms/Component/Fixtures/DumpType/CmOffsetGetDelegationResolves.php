<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class CmOffsetGetDelegateInner extends Control
{

	public function createForm(): Form
	{
		$form = new Form();
		$form->addHidden('action');

		return $form;
	}

}

final class CmOffsetGetDelegate extends Control
{

	private CmOffsetGetDelegateInner $delegate;

	public function createForm(): Form
	{
		return $this->delegate->createForm();
	}

}

final class CmOffsetGetDelegationResolves extends Control
{

	private CmOffsetGetDelegate $inner;

	public function go(): void
	{
		$f = $this->inner->createForm();
		dumpType($f['action']); // => Nette\Forms\Controls\HiddenField
	}

}
