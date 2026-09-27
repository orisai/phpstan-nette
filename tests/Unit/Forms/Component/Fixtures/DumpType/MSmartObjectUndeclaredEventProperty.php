<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\SmartObject;
use function PHPStan\dumpType;

final class MSmartObjectUndeclaredEventPropertyEmitter
{

	use SmartObject;

}

final class MSmartObjectUndeclaredEventProperty
{

	public function go(): void
	{
		$emitter = new MSmartObjectUndeclaredEventPropertyEmitter();
		dumpType($emitter->onAction); // => array<int, callable(): mixed>
	}

}
