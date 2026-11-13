<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

// An offset whose key is one of several literal names is one of exactly those children, on every
// channel: the builder's own form variable, a form read off the component, and a bound copy of it.
// A key naming something the shape does not hold keeps the union of every child.
final class MLiteralNameUnionOffset extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addCheckbox('first');
		$form->addCheckbox('second');
		$form->addText('note');

		foreach (['first', 'second'] as $name) {
			dumpType($form[$name]); // => Nette\Forms\Controls\Checkbox
		}

		return $form;
	}

	public function read(bool $flag): void
	{
		$name = $flag ? 'first' : 'second';
		dumpType($this['form'][$name]); // => Nette\Forms\Controls\Checkbox

		$form = $this['form'];
		dumpType($form[$name]); // => Nette\Forms\Controls\Checkbox

		$other = $flag ? 'first' : 'nope';
		dumpType($form[$other]); // => Nette\Forms\Controls\Checkbox|Nette\Forms\Controls\TextInput
	}

}
