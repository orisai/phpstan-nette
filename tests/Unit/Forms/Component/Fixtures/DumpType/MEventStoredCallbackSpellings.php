<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Closure;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * The spellings an event-property store comes in, and which of them the walk can READ.
 *
 * Reading one matters because the answer turns on the callable's body: a callable seen to register a
 * component makes the render-time shape unprovable and opens it, while the handler that merely
 * processes submitted values — nearly all of them — leaves it closed, so the absence findings a
 * closed shape exists to produce are kept. processOnly is that control, and its missing name is
 * still PROVEN absent.
 *
 * A callable a captured form reaches is dropped from the walk as well as opening the shape, exactly
 * as a proven-later callable argument's ops are: the store defers them past the factory, so a
 * render-time reader never sees them, and a shape that listed them would be claiming a control that
 * is not attached yet.
 *
 * fromProperty is the residual: a callable read off a property names no body, so the store is
 * invisible and the shape stays closed. Its missing name reads *ERROR* on a control that an
 * onSuccess handler may well attach — the one place this fixture pins a claim that is not earned.
 */
final class MEventStoredCallbackSpellings extends Control
{

	/** @var callable(ApplicationForm, mixed): void */
	private $storedHandler;

	/**
	 * @param callable(ApplicationForm, mixed): void $storedHandler
	 */
	public function __construct(callable $storedHandler)
	{
		$this->storedHandler = $storedHandler;
	}

	protected function createComponentLiteralParam(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess[] = static function (ApplicationForm $f): void {
			$f->addText('viaParam');
		};

		return $form;
	}

	protected function createComponentCaptured(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess[] = function () use ($form): void {
			$form->addText('viaCapture');
		};

		return $form;
	}

	protected function createComponentArrayCallable(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess[] = [$this, 'registersOnForm'];

		return $form;
	}

	protected function createComponentFromCallable(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess[] = Closure::fromCallable([$this, 'registersOnForm']);

		return $form;
	}

	protected function createComponentWholeList(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess = [
			static function (ApplicationForm $f): void {
				$f->addText('viaWholeList');
			},
		];

		return $form;
	}

	protected function createComponentOnControl(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$button = $form->addSubmit('send');
		$button->onClick[] = function () use ($form): void {
			$form->addText('viaButton');
		};

		return $form;
	}

	protected function createComponentProcessOnly(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess[] = [$this, 'processesOnly'];

		return $form;
	}

	protected function createComponentFromProperty(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->onSuccess[] = $this->storedHandler;

		return $form;
	}

	public function registersOnForm(ApplicationForm $f): void
	{
		$f->addText('viaHandler');
	}

	public function processesOnly(ApplicationForm $f): void
	{
		$f->addError('not a registration');
	}

	public function go(): void
	{
		dumpType($this['literalParam']['viaParam']); // => mixed
		dumpType($this['literalParam']['base']); // => Nette\Forms\Controls\TextInput
		dumpType($this['captured']['viaCapture']); // => mixed
		dumpType($this['captured']->getValues()); // => Nette\Utils\ArrayHash{base: string, ...<mixed>}
		dumpType($this['arrayCallable']['viaHandler']); // => mixed
		dumpType($this['fromCallable']['viaHandler']); // => mixed
		dumpType($this['wholeList']['viaWholeList']); // => mixed
		dumpType($this['onControl']['viaButton']); // => mixed
		dumpType($this['processOnly']['viaHandler']); // => *ERROR*
		dumpType($this['processOnly']->getValues()); // => Nette\Utils\ArrayHash{base: string}
		dumpType($this['fromProperty']['viaHandler']); // => *ERROR*
	}

}
