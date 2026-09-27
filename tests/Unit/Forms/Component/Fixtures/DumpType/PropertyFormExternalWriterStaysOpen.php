<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Form;
use function PHPStan\dumpType;

class PropertyFormExternalWriterHeldForm extends Form
{

}

/**
 * The row that protects the whole design: an unrelated class really does add a control to this
 * form from outside, through the public property. The per-class fold cannot see that body, so the
 * shape must keep its open tail — a closed shape here would answer "this field does not exist"
 * about 'outside', which exists.
 */
final class PropertyFormExternalWriterTarget
{

	public PropertyFormExternalWriterHeldForm $form;

	public function __construct()
	{
		$this->form = new PropertyFormExternalWriterHeldForm();
		$this->form->addText('inside');
	}

	public function go(): void
	{
		dumpType($this->form->getValues()); // => Nette\Utils\ArrayHash{inside: string, ...<mixed>}
	}

}

final class PropertyFormExternalWriterStaysOpen
{

	public function write(PropertyFormExternalWriterTarget $target): void
	{
		$target->form->addText('outside');
	}

}
