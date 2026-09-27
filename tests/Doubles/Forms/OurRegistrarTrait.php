<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms;

use Nette\Forms\Controls\TextInput;

/**
 * A registrar of OURS composed into a container that is not: the class the method is reached through
 * lives outside the analysed paths, but the declaration does not, and it is the declaration the line
 * is drawn around. Written to refute the convention, so anything but the declaration's own file
 * deciding would open it.
 *
 * @mixin \Nette\Forms\Container
 */
trait OurRegistrarTrait
{

	public function addSharedLabelled(string $label, string $name): TextInput
	{
		return $this->addText($name)->setCaption($label);
	}

}
