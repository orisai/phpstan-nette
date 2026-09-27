<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

interface FormFactory_UndeclaredFactoryReturnStaysIComponent
{

	public function create(): Form;

}

final class Outer_UndeclaredFactoryReturnStaysIComponent extends Control
{

	private FormFactory_UndeclaredFactoryReturnStaysIComponent $factory;

	public function __construct(FormFactory_UndeclaredFactoryReturnStaysIComponent $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentInner(): Form
	{
		$inner = $this->factory->create();

		return $inner;
	}

	public function go(): void
	{
		dumpType($this['inner']['form']); // => Nette\ComponentModel\IComponent
	}

}
