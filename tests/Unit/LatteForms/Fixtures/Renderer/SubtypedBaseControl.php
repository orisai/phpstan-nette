<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The DECLARED type of IndirectMutator::createComponentSubtypedCtrl, and therefore the only owner
// class the index can name for a mutation spelled through $this['subtypedCtrl'].
abstract class SubtypedBaseControl extends PairingControl
{

}
