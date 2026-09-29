<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference\PlainGetComponents;

use Nette\Forms\Container;
use function PHPStan\Testing\assertType;

final class G3GetComponents
{

	public function each(Container $containers): void
	{
		foreach ($containers->getComponents() as $child) {
			assertType('Nette\ComponentModel\IComponent', $child);
		}
	}

}
