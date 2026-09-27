<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

interface EditFormFactory
{

	public function create(): ApplicationForm;

}

final class ClassComponentFactoryResolves extends Control
{

	private EditFormFactory $factory;

	public function __construct(EditFormFactory $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentEdit(): ApplicationForm
	{
		$f = $this->factory->create();
		$f->addText('email');

		return $f;
	}

	public function go(): void
	{
		dumpType($this['edit']['email']); // => Nette\Forms\Controls\TextInput
	}

}
