<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Application\UI\Form;
use Nette\Forms\Controls\TextInput;

/**
 * A specific-form subclass whose build method is called by whoever holds the form rather than by its
 * own constructor. The generic adder beside it is the contrast: that one IS annotatable, and the build
 * method next to it never could be.
 */
final class SpecificFactoryBuiltForm extends Form
{

	public function buildEverything(): void
	{
		$this->addText('alpha');
		$this->addSelect('beta', 'Beta', ['a' => 'A']);
	}

	/**
	 * @form-adds $name
	 */
	public function addOnlyOne(string $name): TextInput
	{
		return $this->addText($name);
	}

}
