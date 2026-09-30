<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * The same immediately-invoked runner is handed a form-mutating callback by two different builders.
 * Each callback closes over its OWN form, so the adds land only on that form: the timing decision is
 * per call site, not per runner method.
 *
 * An IMMEDIATELY-invoked callback has run by the time the form is returned, so its add is in the
 * shape and the shape stays closed — which makes the other form's field proven absent rather than
 * unresolved. It reads *ERROR* with one orisai.nette.forms.noSuchComponent; a runner-keyed contribution would
 * read as a resolved control instead.
 */
final class MCallbackTimingKeepsFormsSeparate extends Control
{

	protected function createComponentA(): ApplicationForm
	{
		$form = new ApplicationForm();
		$this->now(function () use ($form): void {
			$form->addText('aViaCallback');
		});

		return $form;
	}

	protected function createComponentB(): ApplicationForm
	{
		$form = new ApplicationForm();
		$this->now(function () use ($form): void {
			$form->addCheckbox('bViaCallback');
		});

		return $form;
	}

	/**
	 * @param-immediately-invoked-callable $callback
	 * @param callable(): void $callback
	 */
	private function now(callable $callback): void
	{
		$callback();
	}

	public function go(): void
	{
		dumpType($this['a']->getValues(true)); // => array{aViaCallback: string}
		dumpType($this['b']->getValues(true)); // => array{bViaCallback: bool}
		dumpType($this['a']['bViaCallback']); // => *ERROR*
		dumpType($this['b']['aViaCallback']); // => *ERROR*
	}

}
