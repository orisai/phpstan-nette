<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

// SmartObject reads `$form->values` through getValues(): the property is the same projection on a tracked local, on a
// bound handler's parameter and on a closure's, and writing a field the form has not got is not a read of it.
final class ValuesPropertyIsGetValues extends Control
{

	protected function createComponentProfile(): Form
	{
		$form = new Form();
		$form->addText('name')->setRequired();
		$form->addCheckbox('agree');
		dumpType($form->values); // => Nette\Utils\ArrayHash{name: string, agree: bool}
		$form->onSuccess[] = [$this, 'profileSucceeded'];
		$form->onSuccess[] = static function (Form $form): void {
			dumpType($form->values); // => Nette\Utils\ArrayHash{name: non-empty-string, agree: bool}
		};

		return $form;
	}

	public function profileSucceeded(Form $form): void
	{
		dumpType($form->values); // => Nette\Utils\ArrayHash{name: non-empty-string, agree: bool}
		$values = $form->values;
		$values->extra = 1;
		self::read($values->absent); // !! Form value 'absent' does not exist.
	}

	public function unbound(Form $form): void
	{
		dumpType($form->values); // => Nette\Utils\ArrayHash
	}

	/**
	 * @param mixed $value
	 */
	private static function read($value): void
	{
	}

}
