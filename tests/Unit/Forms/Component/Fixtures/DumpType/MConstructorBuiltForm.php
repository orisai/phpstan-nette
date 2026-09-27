<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

/**
 * A form that builds its own controls in its constructor (new XxxForm()) is shaped on
 * demand from reflection + the constructor AST — both `$this->addX('name')` and
 * `$this['name'] = new XInput()` — so getValues() in the handler is the filled shape
 * (required fields narrow) even though no addX() runs in the factory.
 */
class MConstructorBuiltFormType extends RawForm
{

	public function __construct()
	{
		parent::__construct();

		$this->addHidden('reservationId');
		$this->addTextArea('note')->setRequired();
		$this['scenario'] = new TextInput('Scenario');
	}

}

final class MConstructorBuiltForm extends Control
{

	protected function createComponentForm(): MConstructorBuiltFormType
	{
		$form = new MConstructorBuiltFormType();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(MConstructorBuiltFormType $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{reservationId: string|null, note: non-empty-string, scenario: string}
	}

}
