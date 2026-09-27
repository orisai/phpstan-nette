<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * Whether a form-mutating callback's adds belong in the shape is decided by PHPStan's own
 * invocation-timing trinary (@param-immediately-invoked-callable / @param-later-invoked-callable),
 * read off the parameter the callable lands on. The three answers are deliberately asymmetric:
 *
 * - YES: the callback has run by the time the form is returned, so its adds are in the shape.
 * - NO: it has not, so the shape is the render-time one and the control is genuinely absent.
 * - MAYBE: neither can be claimed, so the shape OPENS. Claiming absence is the dangerous side —
 *   absence is what turns into a report — so an unknown timing may never produce one.
 *
 * The three answers below are that asymmetry, read off the form-level shape the access now carries.
 * NO closes the shape, so viaLater is PROVEN absent: *ERROR*, with one orisaiNette.forms.noSuchComponent.
 * MAYBE opens it, so viaUnknown is merely unresolvable and degrades to the open shape's carrier with
 * NOTHING reported — the rule's own lost-field arm stays silent for an opened shape. An unknown
 * timing producing a report is the regression this pair exists to catch, and mixed rather than
 * IComponent is what an open shape degrades to on both channels.
 */
final class MCallbackInvocationTiming extends Control
{

	/** @var list<callable(): void> */
	private array $deferred = [];

	protected function createComponentImmediate(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$this->now(function () use ($form): void {
			$form->addText('viaImmediate');
		});

		return $form;
	}

	protected function createComponentLater(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$this->later(function () use ($form): void {
			$form->addText('viaLater');
		});

		return $form;
	}

	protected function createComponentUnknown(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$this->undocumented(function () use ($form): void {
			$form->addText('viaUnknown');
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

	/**
	 * @param-later-invoked-callable $callback
	 * @param callable(): void $callback
	 */
	private function later(callable $callback): void
	{
		$this->deferred[] = $callback;
	}

	/**
	 * @param callable(): void $callback
	 */
	private function undocumented(callable $callback): void
	{
		$this->deferred[] = $callback;
	}

	public function runDeferred(): void
	{
		foreach ($this->deferred as $callback) {
			$callback();
		}
	}

	public function go(): void
	{
		dumpType($this['immediate']->getValues(true)); // => array{base: string, viaImmediate: string}
		dumpType($this['later']->getValues(true)); // => array{base: string}
		dumpType($this['unknown']->getValues(true)); // => array{base: string, ...<string, mixed>}
		dumpType($this['immediate']['viaImmediate']); // => Nette\Forms\Controls\TextInput
		dumpType($this['later']['viaLater']); // => *ERROR*
		dumpType($this['unknown']['viaUnknown']); // => mixed
	}

}
