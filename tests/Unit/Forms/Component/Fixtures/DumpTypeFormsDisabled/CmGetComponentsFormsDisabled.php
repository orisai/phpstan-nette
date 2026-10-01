<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpTypeFormsDisabled;

use Nette\Forms\Container;
use function PHPStan\dumpType;

final class CmGetComponentsFormsDisabled
{

	public function go(Container $container): void
	{
		dumpType($container->getComponents()); // => array<int|string, Nette\ComponentModel\IComponent>
	}

}
