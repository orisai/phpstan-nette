<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;

final class RawFormFactory
{

	public function create(): RawForm
	{
		return new RawForm();
	}

}
