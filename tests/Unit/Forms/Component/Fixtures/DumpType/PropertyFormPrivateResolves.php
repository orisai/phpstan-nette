<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Form;
use function PHPStan\dumpType;

class PropertyFormPrivateHeldForm extends Form
{

}

/**
 * A form held in a PRIVATE property, built and configured in the constructor: every write to it
 * lives in a body of this class, all of which the per-class fold reads, so the shape may close.
 * The offset rows prove the chain walk descends from the property's own shape exactly as it does
 * from a component slot.
 */
final class PropertyFormPrivateResolves
{

	private PropertyFormPrivateHeldForm $form;

	public function __construct()
	{
		$this->form = new PropertyFormPrivateHeldForm();
		$this->form->addText('name');
		$this->form->addSelect('choice', null, ['a' => 'A', 'b' => 'B']);
		$sub = $this->form->addContainer('sub');
		$sub->addText('deep');
		$this->form->addContainer('empty');
	}

	public function go(): void
	{
		dumpType($this->form->getValues()); // => Nette\Utils\ArrayHash{name: string, choice: 'a'|'b'|null, sub: Nette\Utils\ArrayHash{deep: string}, empty: Nette\Utils\ArrayHash{}}
		dumpType($this->form['name']); // => Nette\Forms\Controls\TextInput
		dumpType($this->form['sub']['deep']); // => Nette\Forms\Controls\TextInput
		dumpType($this->form['sub']->getValues()); // => Nette\Utils\ArrayHash{deep: string}
		// The per-control read type through a property root (resolveControlValueType), pinned on a
		// select rather than on the text input above: its item KEYS come out of the shape this
		// channel resolves, so no answer read off the control's class alone could produce them.
		dumpType($this->form['choice']->getValue()); // => 'a'|'b'|null
		// A childless container carries no shape of its own, so this receiver reaches the shape
		// resolver as a property root with a chain still on it — the one row that pins the chain
		// descending from index 0. A property root has consumed no segment to obtain its shape,
		// unlike a component root, whose first segment named the component that produced it.
		dumpType($this->form['empty']->getValues()); // => Nette\Utils\ArrayHash{}
	}

}
