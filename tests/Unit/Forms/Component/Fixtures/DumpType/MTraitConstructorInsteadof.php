<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

trait MInsteadofTraitA
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('fromTraitA')->setRequired();
	}

}

trait MInsteadofTraitB
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('fromTraitB')->setRequired();
	}

}

class MTraitConstructorInsteadofType extends RawForm
{

	use MInsteadofTraitA, MInsteadofTraitB {
		MInsteadofTraitA::__construct insteadof MInsteadofTraitB;
	}

}

final class MTraitConstructorInsteadof extends Control
{

	protected function createComponentForm(): MTraitConstructorInsteadofType
	{
		$form = new MTraitConstructorInsteadofType();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(MTraitConstructorInsteadofType $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{fromTraitA: non-empty-string}
	}

}
