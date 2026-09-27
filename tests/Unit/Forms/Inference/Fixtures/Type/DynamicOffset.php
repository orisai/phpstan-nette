<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\Testing\assertType;

/**
 * A NON-CONSTANT offset on a shaped receiver, which is the one offset spelling the projector never
 * sees: FormAccessExpressionTypeResolver::offsetType() needs a single constant string and declines
 * without one, so the expression falls to the synthetic offsetGet() call and lands in
 * ContainerModel::resolveFromFormShapeReceiver(). That arm used to answer with the RECEIVER's own
 * shape - `$form[$name]` typed as the form itself - which is not a degrade but a wrong answer: every
 * later member access on it was checked against the form's class rather than a control's.
 *
 * The answer is the union of the children the shape holds, which is what the same expression already
 * resolves to through ContainerModel's own REPLICATOR_OFFSET_SENTINEL arm (the channel every .latte
 * and every non-building consumer goes through, pinned by DynamicOffsetUnionsChildren and
 * MDynamicOffsetUnionResolves). One expression, two channels, one answer.
 */
final class DynamicOffset
{

	public function slotsUnion(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addCheckbox('b');

		assertType('Nette\Forms\Controls\Checkbox|Nette\Forms\Controls\TextInput', $form[$name]);
	}

	public function slotAndContainerUnion(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$inner = $form->addContainer('c');
		$inner->addText('z');

		assertType(
			'Nette\Forms\Controls\TextInput|Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{z: string}',
			$form[$name],
		);
	}

	/**
	 * A shape holding no child at all has nothing to union, so the arm declines and the access keeps
	 * whatever the wrapped class's own ArrayAccess stub answers - the degrade, in the one case the
	 * receiver's own shape used to look like a plausible answer.
	 */
	public function childlessShapeDegrades(string $name): void
	{
		$form = new ApplicationForm();

		assertType('Nette\ComponentModel\IComponent', $form[$name]);
	}

}
