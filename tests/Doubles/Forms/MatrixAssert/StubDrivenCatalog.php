<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\TextInput;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class StubDrivenCatalog
{

	public function addMethods(): void
	{
		$form = new ApplicationForm();
		$form->addText('t');
		$form->addCheckbox('c');
		$form->addSelect('s');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  c: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			  s: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			  t: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function offsetAndComponent(): void
	{
		$form = new ApplicationForm();
		$form['t'] = new TextInput();
		$form->addComponent(new Checkbox(), 'c');
		$form['s'] = new SelectBox();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  c: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			  s: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			  t: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

}
