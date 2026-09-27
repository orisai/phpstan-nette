<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Helpers\VPropertyFetchReturnInner;
use function PHPStan\dumpType;

final class VPropertyFetchReturnResolves extends Control
{

	private VPropertyFetchReturnInner $inner;

	protected function createComponentChild(): VPropertyFetchReturnInner
	{
		return $this->inner;
	}

	public function go(): void
	{
		dumpType($this['child']['form']['bag']); // => Nette\Forms\Controls\HiddenField
	}

}
