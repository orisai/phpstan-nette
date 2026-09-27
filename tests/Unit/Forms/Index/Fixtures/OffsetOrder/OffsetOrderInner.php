<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\OffsetOrder;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class OffsetOrderInner extends Control
{

	private OffsetOrderFactory $factory;

	public function go(): void
	{
		dumpType($this['form']['field']);
	}

	protected function createComponentForm(): Form
	{
		$form = $this->factory->create();

		return $form;
	}

}
