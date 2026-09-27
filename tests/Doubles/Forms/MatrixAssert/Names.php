<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Names
{

	private const NAME = 'a';

	private const SUF = 'x';

	public function n01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function n02(): void
	{
		$form = new ApplicationForm();
		$form->addText(self::NAME);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function n03(): void
	{
		$form = new ApplicationForm();
		$form->addText('pre_' . self::SUF);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  pre_x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function n04(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText($name);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/** @param list<string> $names */
	public function n05(array $names): void
	{
		$form = new ApplicationForm();
		foreach ($names as $i) {
			$form->addText("f_$i");
		}
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function n06(bool $flag): void
	{
		$form = new ApplicationForm();
		$name = 'a';
		if ($flag) {
			$name = 'b';
		}
		$form->addText($name);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function n07(bool $flag): void
	{
		$form = new ApplicationForm();
		$name = 'a';
		if ($flag) {
			foreach (['b'] as $name) {
			}
		}
		$form->addText($name);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

}
