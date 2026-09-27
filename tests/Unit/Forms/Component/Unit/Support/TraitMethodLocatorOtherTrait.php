<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

trait TraitMethodLocatorOtherTrait
{

	public function aliasedInTrait(): string
	{
		return 'fromOtherTrait';
	}

}
