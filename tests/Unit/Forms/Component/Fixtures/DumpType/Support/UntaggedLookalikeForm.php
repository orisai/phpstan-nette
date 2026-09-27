<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Forms\Container;
use Nette\Forms\Form;

final class UntaggedLookalikeForm extends Form
{

	/**
	 * @param callable(Container): void $factory
	 */
	public function addWidget(string $name, callable $factory, int $copies = 1): UntaggedLookalike
	{
		$control = new UntaggedLookalike();

		return $this[$name] = $control;
	}

}
