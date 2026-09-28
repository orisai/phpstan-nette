<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// A project-declared FREE FUNCTION that registers on the component it is handed. Nothing autoloads
// it - the registration index reads it syntactically, which is the whole point of the fixture.
function decorateFreeForm(PairingForm $form): void
{
	$form->addHidden('late');
}
