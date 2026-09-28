<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Nette\ComponentModel\Container;

// A component that is neither a form nor an ancestor of one: Nette\Forms\Form is not an instance of
// this class and no instance of this class is a form, so {form X} naming it can never render.
final class NonFormComponent extends Container
{

}
