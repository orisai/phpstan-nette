<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Inherits the same component from the same base, but no site can ever reach it: no instance of the
// mutated subclass is one of these, so this class must keep its gate.
final class InheritedSiblingControl extends InheritedBaseControl
{

}
