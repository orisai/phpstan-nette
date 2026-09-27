<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

class PropertyFormUnbuilt
{

	private PlainFactoryForm $never;

	private DirectFormFactory $service;

	private object $thing;

	public function __construct()
	{
		$this->service = new DirectFormFactory();
		$this->thing = new DirectFormFactory();
	}

}
