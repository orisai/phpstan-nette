<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

final class UnfollowableFactory
{

	private FactoryBuiltForm $prebuilt;

	public function create(): FactoryBuiltForm
	{
		return $this->prebuilt;
	}

}
