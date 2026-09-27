<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

trait MOverriddenTraitBuilder
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('fromTrait')->setRequired();
	}

}

class MTraitConstructorClassOverridesType extends RawForm
{

	use MOverriddenTraitBuilder;

	public function __construct()
	{
		parent::__construct();

		$this->addText('fromClass')->setRequired();
	}

}

final class MTraitConstructorClassOverrides extends Control
{

	protected function createComponentForm(): MTraitConstructorClassOverridesType
	{
		$form = new MTraitConstructorClassOverridesType();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(MTraitConstructorClassOverridesType $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{fromClass: non-empty-string}
	}

}
