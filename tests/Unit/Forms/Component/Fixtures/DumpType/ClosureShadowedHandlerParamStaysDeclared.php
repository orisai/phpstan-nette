<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

/**
 * The handler param `$form` narrows to the registered ApplicationForm by NAME match against the
 * enclosing method's params (ContainerModel::paramClassForVariable). A closure declared inside the
 * handler with its own `Form $form` parameter shadows that name with a DIFFERENT binding — the outer
 * registration must not be attributed to it, so the closure's own read stays at its declared base
 * Form rather than being wrongly narrowed to ApplicationForm.
 */
final class ClosureShadowedHandlerParamStaysDeclared extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->onSuccess[] = [$this, 'onOk'];

		return $form;
	}

	public function onOk(Form $form): void
	{
		dumpType($form); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm

		$read = static function (Form $form): void {
			dumpType($form); // => Nette\Application\UI\Form
		};
		$read($form);
	}

}
