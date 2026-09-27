<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class DynamicOffsetUnionsChildren extends Control
{

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$form->addText('a');

		return $form;
	}

	public function pick(string $name): void
	{
		dumpType($this['form'][$name]); // => Nette\Forms\Controls\TextInput
	}

}
