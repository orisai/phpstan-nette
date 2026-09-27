<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Unknown
{

	public function u01(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText($name);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function u02(): void
	{
		$form = new ApplicationForm();
		$form->addSomethingUnknown();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function u03(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addText($name);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function u04(): void
	{
		$form = new ApplicationForm();
		$form->addReCaptcha('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Control\ReCaptchaField,
			}
			OUTPUT);
	}

	public function n03NonForm(): void
	{
		$form = new \stdClass();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			stdClass
			OUTPUT);
	}

}
