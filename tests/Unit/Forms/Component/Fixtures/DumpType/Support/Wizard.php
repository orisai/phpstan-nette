<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

abstract class Wizard extends Control
{

	/**
	 * @return array<int, mixed>
	 */
	public function getValues(): array
	{
		return [];
	}

	protected function createForm(): Form
	{
		return new Form();
	}

}
