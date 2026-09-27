<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Forms\Form;
use function PHPStan\dumpType;

class ApplicationForm extends Form
{

}

final class FormFactory_PlainFileFactoryForm
{

	public function createForNonApp(): ApplicationForm
	{
		return new ApplicationForm();
	}

}

$factory = new FormFactory_PlainFileFactoryForm();
$form = $factory->createForNonApp();
$form->addText('name');

dumpType($form['name']); // => Nette\Forms\Controls\TextInput
