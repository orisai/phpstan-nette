<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Nette\Application\UI\Form;

// Replicates this repo's own App\Base\Form\ApplicationForm: a Form subclass that declares no
// constructor of its own. ConstructorFormShapeResolver skips an inherited constructor, so a form
// built from this class carries no constructor_build marker - which `new Nette\Application\UI\Form`
// does carry, and which would make every fixture form open.
final class PairingForm extends Form
{

}
