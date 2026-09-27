<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * The factory returns `new CtorFieldForm()` whose constructor adds a control: the factory
 * compensation must carry the constructor-built field instead of fabricating an empty shape.
 */
class CtorFieldForm_MFactoryNewCtorFields extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$this->addText('fromCtor');
	}

}

class CtorFormFactory_MFactoryNewCtorFields
{

	public function create(): CtorFieldForm_MFactoryNewCtorFields
	{
		return new CtorFieldForm_MFactoryNewCtorFields();
	}

}

final class MFactoryNewCtorFieldsResolves extends Control
{

	private CtorFormFactory_MFactoryNewCtorFields $factory;

	protected function createComponentForm(): CtorFieldForm_MFactoryNewCtorFields
	{
		$form = $this->factory->create();
		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string, fromCtor: string}
	}

}
