<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Nette\Application\UI\Control;

// A renderer that is itself a component, so a parent's createComponent* for it declares an
// IComponent return type and the index can name the owner of a mutation spelled through $this.
abstract class PairingControl extends Control
{

}
