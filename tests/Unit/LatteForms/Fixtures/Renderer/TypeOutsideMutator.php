<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class TypeOutsideMutator
{

	public function createComponentTypedHost(): TypeMutatedRenderer
	{
		$control = new TypeMutatedRenderer();
		$control['typedForm']->addHidden('late');

		return $control;
	}

}
