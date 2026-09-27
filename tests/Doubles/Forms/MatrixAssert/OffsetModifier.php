<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Controls\TextInput;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

final class OffsetModifier
{

	public function offsetReceiver(): void
	{
		$form = new ApplicationForm();
		$form['x'] = new TextInput();
		$form['x']->setNullable();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, non-empty-string|null>,
			}
			OUTPUT);
	}

	public function getComponentReceiver(): void
	{
		$form = new ApplicationForm();
		$form['y'] = new TextInput();
		$form->getComponent('y')->setNullable();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  y: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, non-empty-string|null>,
			}
			OUTPUT);
	}

	public function offsetOmitted(): void
	{
		$form = new ApplicationForm();
		$form['z'] = new TextInput();
		$form['z']->setOmitted();
		assertFormValues($form, 'Nette\Utils\ArrayHash{}');
	}

}
