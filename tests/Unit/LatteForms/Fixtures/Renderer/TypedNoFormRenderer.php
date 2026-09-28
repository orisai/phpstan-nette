<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class TypedNoFormRenderer
{

	public function createComponentUnrelatedForm(): PairingForm
	{
		return new PairingForm();
	}

}
