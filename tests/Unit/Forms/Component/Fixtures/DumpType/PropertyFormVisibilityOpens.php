<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Form;
use function PHPStan\dumpType;

class PropertyFormVisibilityHeldForm extends Form
{

}

/**
 * The SAME constructor body as the private row, differing only in the properties' visibility. A
 * public property is writable by any caller and a protected one by any subclass body, neither of
 * which the per-class fold enumerates, so both stay OPEN — the visible controls still resolve.
 */
class PropertyFormVisibilityOpens
{

	public PropertyFormVisibilityHeldForm $publicForm;

	protected PropertyFormVisibilityHeldForm $protectedForm;

	public function __construct()
	{
		$this->publicForm = new PropertyFormVisibilityHeldForm();
		$this->publicForm->addText('name');

		$this->protectedForm = new PropertyFormVisibilityHeldForm();
		$this->protectedForm->addText('name');
	}

	public function go(): void
	{
		dumpType($this->publicForm->getValues()); // => Nette\Utils\ArrayHash{name: string, ...<mixed>}
		dumpType($this->protectedForm->getValues()); // => Nette\Utils\ArrayHash{name: string, ...<mixed>}
		dumpType($this->publicForm['name']); // => Nette\Forms\Controls\TextInput
	}

}
