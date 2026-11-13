<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\FactoryBuiltEditForm;
use function PHPStan\dumpType;

// The form is built in another class's method and read here, where `$form` is typed by the builder's
// declared Nette\Forms\Form. The container class comes from the builder's own receiver - RawForm's
// addContainer(): FormContainer - never from this scope's `$form`; and an offset whose key is a union
// of literal names is the union of exactly those children.
final class MFactoryBuiltFormReadFromForeignScope
{

	public function read(FactoryBuiltEditForm $builder): void
	{
		$form = $builder->createForm();
		dumpType($form['box']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}

		foreach (['first', 'second'] as $name) {
			dumpType($form[$name]); // => Nette\Forms\Controls\Checkbox
			dumpType($form[$name]->getControlPart()); // => Nette\Utils\Html
		}
	}

}
