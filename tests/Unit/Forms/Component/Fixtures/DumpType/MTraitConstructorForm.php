<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

trait MTraitConstructorBuilder
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('fromTrait')->setRequired();
	}

}

class MTraitConstructorFormType extends RawForm
{

	use MTraitConstructorBuilder;

}

final class MTraitConstructorForm extends Control
{

	protected function createComponentForm(): MTraitConstructorFormType
	{
		$form = new MTraitConstructorFormType();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(MTraitConstructorFormType $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{fromTrait: non-empty-string}
	}

}
