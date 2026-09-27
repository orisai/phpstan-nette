<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use function PHPStan\dumpType;

/**
 * A factory method returns a `$form` variable whose sole binding is `new X()`, and every field
 * comes from X's constructor — the method body adds none. The factory reader's on-demand twin
 * must carry the constructor-built field (ContainerModel::shapeVendorMethod's constructorShape
 * compensation on its var arm) instead of sealing an empty shape.
 */
class VarCtorFieldsForm_MFactoryVarCtorFields extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$this->addText('fromCtor');
	}

}

class VarCtorFactory_MFactoryVarCtorFields
{

	public function create(): VarCtorFieldsForm_MFactoryVarCtorFields
	{
		$form = new VarCtorFieldsForm_MFactoryVarCtorFields();
		$form->onSuccess[] = static function (): void {
		};

		return $form;
	}

}

final class MFactoryVarCtorFieldsResolves extends Control
{

	private VarCtorFactory_MFactoryVarCtorFields $factory;

	public function go(): void
	{
		$form = $this->factory->create();
		dumpType($form['fromCtor']); // => Nette\Forms\Controls\TextInput
	}

}
