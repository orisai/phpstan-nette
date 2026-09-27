<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Helpers\VNestedCreateComponentInner;
use function PHPStan\dumpType;

final class VNestedCreateComponentResolves extends Control
{

	private VNestedCreateComponentInner $inner;

	protected function createComponentChild(): VNestedCreateComponentInner
	{
		$cmp = $this->inner;

		return $cmp;
	}

	public function go(): void
	{
		dumpType($this['child']['form']['id']); // => Nette\Forms\Controls\HiddenField
	}

}
