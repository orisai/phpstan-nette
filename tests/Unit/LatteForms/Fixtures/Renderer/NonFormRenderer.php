<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class NonFormRenderer
{

	public function createComponentSimpleForm(): NonFormComponent
	{
		$component = new NonFormComponent();
		$component->addComponent(new NonFormComponent(), 'child');

		return $component;
	}

}
