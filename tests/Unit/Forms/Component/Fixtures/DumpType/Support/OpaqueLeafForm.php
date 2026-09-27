<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;

final class OpaqueLeafForm extends RawForm
{

	public function addOpaque(string $name): OpaqueLeaf
	{
		$control = new OpaqueLeaf();

		return $this[$name] = $control;
	}

}
