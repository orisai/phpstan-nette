<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Form;
use Nette\Forms\Container;

final class MultiplierForm extends Form
{

	/**
	 * @param callable(Container): void $factory
	 */
	public function addMultiplier(string $name, callable $factory, int $copies = 1): Multiplier
	{
		$control = new Multiplier();

		return $this[$name] = $control;
	}

}
