<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use function PHPStan\dumpType;

final class MSmartObjectMissingTraitEmitter
{

}

final class MSmartObjectMissingTraitStaysError
{

	public function go(): void
	{
		$emitter = new MSmartObjectMissingTraitEmitter();
		dumpType($emitter->onAction); // => *ERROR*
	}

}
