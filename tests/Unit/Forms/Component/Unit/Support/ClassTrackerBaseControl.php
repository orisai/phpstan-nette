<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Nette\Application\UI\Control;

abstract class ClassTrackerBaseControl extends Control
{

	private ClassTrackerFormFactory $formFactory;

	public function __construct(ClassTrackerFormFactory $formFactory)
	{
		$this->formFactory = $formFactory;
	}

	protected function inheritedFactory(): ClassTrackerFormFactory
	{
		return $this->formFactory;
	}

}
