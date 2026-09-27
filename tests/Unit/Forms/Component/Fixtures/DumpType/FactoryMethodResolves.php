<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class FactoryMethodResolves extends Control
{

	public function buildForm(): Form
	{
		$form = new Form();
		$form->addText('subject');

		return $form;
	}

	public function go(): void
	{
		$f = $this->buildForm();
		dumpType($f['subject']); // => Nette\Forms\Controls\TextInput
	}

}
