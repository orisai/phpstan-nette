<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

class DisabledInConstructorType extends RawForm
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('visible');
		$this->addText('secret')->setDisabled();
	}

}

final class DisabledInConstructor extends Control
{

	protected function createComponentForm(): DisabledInConstructorType
	{
		$form = new DisabledInConstructorType();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(DisabledInConstructorType $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{visible: string}
	}

}
