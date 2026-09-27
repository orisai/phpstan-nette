<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class HeaderExpressionAdds
{

	public function ifConditionAdd(bool $c): void
	{
		$form = new ApplicationForm();
		if (($x = $form->addCheckbox('f')) !== null && $c) {
			$form->addText('a');
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  f: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			}
			OUTPUT);
	}

	public function foreachExpressionContainer(): void
	{
		$form = new ApplicationForm();
		foreach ($form->addContainer('rows')->getComponents() as $r) {
			$r->getName();
		}

		// A container added in a header is registered but not child-composed or class-resolved
		// (that is attachChildShapes' job, which compound headers bypass), so it stands as a
		// classless container whose children are UNKNOWN. It used to stand closed and empty here
		// instead, which reads as a positive claim that the container has none — the C1
		// false-positive class, and the exact value CompositionState::openContainerPlaceholder()'s
		// probe caught reaching FormShapeUnknownAccessRule and ComponentPath::hasDefiniteChild()
		// through this very shape. Open is the honest answer for a container this walk never
		// composed.
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  rows: *mixed*{
			    ...<IComponent>,
			  },
			}
			OUTPUT);
	}

	public function whileConditionAdd(): void
	{
		$form = new ApplicationForm();
		while ($form->addText('a')->getValue() === null) {
			break;
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function forInitAndStep(): void
	{
		$form = new ApplicationForm();
		for ($form->addText('a'); false; $form->addText('b')) {
			$form->addText('c');
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  b?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  c?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function elseifConditionIsConditional(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('a');
		} elseif ($form->addText('b')->getValue() === null) {
			$form->addText('d');
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  b?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  d?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

}
