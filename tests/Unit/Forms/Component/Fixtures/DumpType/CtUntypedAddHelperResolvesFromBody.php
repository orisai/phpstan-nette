<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\TextArea;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

/**
 * An add* helper that declares no return type still names its control — in its own body. PHPStan
 * reflects the DECLARED return and infers nothing from a body, so `addThing()` answers mixed there;
 * the class is read off the helper's returns instead, by the same scope-free resolution the walk
 * already applies to a form variable's defining site. Presence never depended on that reading and
 * still does not: it comes from the add* NAME, which is why `opaque` records a slot at all despite a
 * body whose value is not a control.
 *
 * What the body has to prove is the whole of it. Every return must agree on one class, and the last
 * statement must be a return, since a fall-through path yields null rather than a control. So `arms`
 * (a TextArea on one branch, a TextInput on the other) and `opaque` (no return at all) keep the
 * historical answer, which is what the old name recorded: an unnameable-but-present child degrades to
 * mixed with one orisai.nette.forms.partiallyUnknown beside it. mixed is the deliberate carrier rather than
 * IComponent, which would positively assert the child is ONLY an IComponent and cascade a false
 * undefined-method report onto every member access on a control that really is a BaseControl.
 *
 * The absence axis is untouched throughout: an unresolvable name still degrades, and only a closed
 * shape mints an ErrorType.
 */
final class CtUntypedForm extends RawForm
{

	public function addThing(string $name)
	{
		$control = new TextInput();
		$this[$name] = $control;

		return $control;
	}

	public function addChained(string $name)
	{
		return $this->addText($name);
	}

	public function addArms(string $name, bool $long)
	{
		if ($long) {
			$control = new TextArea();
		} else {
			$control = new TextInput();
		}

		$this[$name] = $control;

		return $control;
	}

	public function addOpaque(string $name)
	{
		$this[$name] = new TextInput();
	}

}

final class CtUntypedAddHelperResolvesFromBody extends Control
{

	protected function createComponentForm(): CtUntypedForm
	{
		$form = new CtUntypedForm();
		$form->addThing('q');
		$form->addChained('chained');
		$form->addArms('arms', true);
		$form->addOpaque('opaque');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['q']); // => Nette\Forms\Controls\TextInput
		dumpType($this['form']['chained']); // => Nette\Forms\Controls\TextInput
		dumpType($this['form']['arms']); // => mixed
		dumpType($this['form']['opaque']); // => mixed
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{q: string, chained: string, arms: mixed, opaque: mixed, ...<mixed>}
	}

}
