<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Receivers
{

	public function r01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function r02(): void
	{
		$form = new \Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function r03(): void
	{
		$form = new \Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The one receiver in this matrix whose class builds itself in its own constructor, so its shape
	 * carries those controls beside the one added here. It used to carry only `a`, which proved the
	 * three constructor-added controls absent; the submit stays out on both sides, since an omitted
	 * control is recorded on neither axis by ConstructorFormShapeResolver.
	 */
	public function r04(): void
	{
		$form = new \Tests\OriPhpstan\Nette\Doubles\Forms\Form\ContactForm();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ContactForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  email: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  message: Nette\Forms\Controls\TextArea<bool|float|int|string|Stringable|null, string>,
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function r05(): void
	{
		$form = new \Nette\Application\UI\Form();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function r06(): void
	{
		$form = new \Nette\Forms\Form();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Nette\Forms\Form{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function r07(bool $c): void
	{
		if ($c) {
			$form = new \Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm();
		} else {
			$form = new \Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm();
		}
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm
			OUTPUT);
	}

}
