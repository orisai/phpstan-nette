<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Helpers\VBuilderChainNonFormControlFactory;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Helpers\VBuilderChainNonFormControlInner;
use function PHPStan\dumpType;

final class VBuilderChainNonFormControlResolves extends Control
{

	private VBuilderChainNonFormControlFactory $factory;

	protected function createComponentChild(): VBuilderChainNonFormControlInner
	{
		return $this->factory->create();
	}

	public function go(): void
	{
		dumpType($this['child']['form']['id']); // => Nette\Forms\Controls\HiddenField
	}

}
