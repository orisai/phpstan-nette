<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class NonVarReturnForm
{

	private DirectFormFactory $factory;

	private FactoryBuiltForm $prebuilt;

	protected function createComponentChainForm(): PlainFactoryForm
	{
		return $this->factory->build();
	}

	protected function createComponentPropForm(): FactoryBuiltForm
	{
		return $this->prebuilt;
	}

}
