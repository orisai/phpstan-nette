<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Forms\Form;
use function PHPStan\dumpType;

class ScopedApplicationForm extends Form
{

}

final class FirstScopedFormFactory
{

	public function create(): ScopedApplicationForm
	{
		return new ScopedApplicationForm();
	}

}

final class SecondScopedFormFactory
{

	public function create(): ScopedApplicationForm
	{
		return new ScopedApplicationForm();
	}

}

class PlainFileVariableClassScopingFirst
{

	public function build(): void
	{
		$factory = new FirstScopedFormFactory();
		$form = $factory->create();
		$form->addText('fromFirst');
		dumpType($form['fromFirst']); // => Nette\Forms\Controls\TextInput
		dumpType($form['fromSecond']); // => Nette\ComponentModel\IComponent
	}

}

class PlainFileVariableClassScopingSecond
{

	public function build(): void
	{
		$factory = new SecondScopedFormFactory();
		$form = $factory->create();
		$form->addCheckbox('fromSecond');
		dumpType($form['fromSecond']); // => Nette\Forms\Controls\Checkbox
		dumpType($form['fromFirst']); // => Nette\ComponentModel\IComponent
	}

}
