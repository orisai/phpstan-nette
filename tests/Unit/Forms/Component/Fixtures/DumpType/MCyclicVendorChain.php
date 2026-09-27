<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

final class MCyclicVendorFactoryA
{
	public function make(): Form
	{
		return (new MCyclicVendorFactoryB())->build();
	}
}

final class MCyclicVendorFactoryB
{
	public function build(): Form
	{
		return (new MCyclicVendorFactoryA())->make();
	}
}

final class MCyclicVendorChain extends Control
{
	protected function createComponentForm(): Form
	{
		return (new MCyclicVendorFactoryA())->make();
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash
	}
}
