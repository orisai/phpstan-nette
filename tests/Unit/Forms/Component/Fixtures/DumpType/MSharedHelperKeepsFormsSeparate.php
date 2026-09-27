<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * One helper adds the same control to two different forms. The helper's contribution is a property
 * of the helper alone, folded into whichever form is being walked, so each form ends up with its own
 * field plus the shared one and NEVER with the other form's field. The mutation proof is built in:
 * `aOnly` exists on A alone and `bOnly` on B alone, so a contribution keyed by method rather than by
 * the receiving form would show up immediately as a merged field set.
 *
 * Each form's shape is CLOSED and non-empty — the whole builder was read — so the other form's field
 * is not merely unresolved, it is PROVEN absent, and the proof is no longer silent: the access reads
 * *ERROR* with exactly one orisaiNette.forms.noSuchComponent beside it, where a leaked contribution would
 * read as a resolved TextInput. FormShapeUnknownAccessRule reads the shape off the receiver, which
 * only carries one now that a form-level access is a FormShapeType.
 */
final class MSharedHelperKeepsFormsSeparate extends Control
{

	protected function createComponentA(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('aOnly');
		$this->addCommon($form);

		return $form;
	}

	protected function createComponentB(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('bOnly');
		$this->addCommon($form);

		return $form;
	}

	private function addCommon(ApplicationForm $form): void
	{
		$form->addText('shared');
	}

	public function go(): void
	{
		dumpType($this['a']->getValues(true)); // => array{aOnly: string, shared: string}
		dumpType($this['b']->getValues(true)); // => array{bOnly: string, shared: string}
		dumpType($this['a']['shared']); // => Nette\Forms\Controls\TextInput
		dumpType($this['b']['shared']); // => Nette\Forms\Controls\TextInput
		dumpType($this['a']['bOnly']); // => *ERROR*
		dumpType($this['b']['aOnly']); // => *ERROR*
	}

}
