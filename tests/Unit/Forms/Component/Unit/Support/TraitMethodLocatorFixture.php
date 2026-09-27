<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

final class TraitMethodLocatorFixture
{

	use TraitMethodLocatorBodyTrait;
	use TraitMethodLocatorOtherTrait {
		aliasedInTrait as renamedFromTrait;
	}

	public function definedInClass(): string
	{
		return 'fromClass';
	}

}
