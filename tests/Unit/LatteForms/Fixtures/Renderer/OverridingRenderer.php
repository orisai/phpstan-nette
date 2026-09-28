<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Nette's own createComponent() hook spelling, declared with a concrete return type - the normal way
// of serving dynamically named components. The class inherits nothing (the resolver keys on the
// method NAME, not on a vendor slot). An EMPTY component name resolves to it ('createComponent' .
// ucfirst('')), so a rule that let a nameless {form $var} site reach the resolver would prove this
// class renders no form under the name ''.
final class OverridingRenderer
{

	public function createComponent(string $name): NonFormComponent
	{
		$component = new NonFormComponent();
		$component->addComponent(new NonFormComponent(), $name);

		return $component;
	}

}
