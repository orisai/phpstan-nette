<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\OffsetOrder;

use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class OffsetOrderOuter extends Control
{

	private OffsetOrderInner $inner;

	public function go(): void
	{
		dumpType($this['inner']['form']['field']);
	}

	protected function createComponentInner(): OffsetOrderInner
	{
		return $this->inner;
	}

}
