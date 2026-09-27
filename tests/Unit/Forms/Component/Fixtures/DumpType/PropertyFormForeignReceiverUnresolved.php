<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Form;
use function PHPStan\dumpType;

class PropertyFormForeignHeldForm extends Form
{

}

/**
 * `$other->form` is the documented v1 NON-GOAL: the owner would have to come from the receiver's
 * type, and every write through every other holder of that object is outside the per-class fold.
 * It resolves to nothing at all — the declared/native types stand — rather than to a shape whose
 * write set was never established.
 */
final class PropertyFormForeignReceiverUnresolved
{

	private PropertyFormForeignHeldForm $form;

	public function __construct()
	{
		$this->form = new PropertyFormForeignHeldForm();
		$this->form->addText('name');
	}

	public function go(self $other): void
	{
		dumpType($other->form->getValues()); // => Nette\Utils\ArrayHash
		dumpType($other->form['name']); // => Nette\ComponentModel\IComponent
	}

}
