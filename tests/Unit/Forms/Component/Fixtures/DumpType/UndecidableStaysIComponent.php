<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function PHPStan\dumpType;

interface OpaqueFactory
{

	public function create(): object;

}

final class UndecidableStaysIComponent extends Control
{

	private OpaqueFactory $factory;

	public function __construct(OpaqueFactory $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentEdit(): Form
	{
		$f = $this->factory->create();

		return $f;
	}

	private function fill(Container $c): void
	{
		dumpType($c['name']); // => Nette\ComponentModel\IComponent
	}

	public function go(): void
	{
		$this->fill($this['edit']);
	}

}
