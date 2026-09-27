<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Component;

use Nette\Application\UI\Control;

final class AnonymousComponentFixture
{

	public function make(): Control
	{
		return new class extends Control {

			public function handleFoo(): int
			{
				return 1;
			}

		};
	}

}
