<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Forms\Container;
use function PHPStan\dumpType;

final class ExternalParamStaysIComponent
{

	public function handle(Container $external): void
	{
		dumpType($external['whatever']); // => Nette\ComponentModel\IComponent
	}

}
